<?php

/**
 * @file
 * Contains \DrupalProject\composer\ScriptHandler.
 */

namespace DrupalProject\composer;

use Composer\Script\Event;
use Composer\Semver\Comparator;
use DrupalFinder\DrupalFinder;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\PathUtil\Path;

use GuzzleHttp\Client;

class ScriptHandler {

  public static function createRequiredFiles(Event $event) {
    $fs = new Filesystem();
    $drupalFinder = new DrupalFinder();
    $drupalFinder->locateRoot(getcwd());
    $drupalRoot = $drupalFinder->getDrupalRoot();

    $dirs = [
      'modules',
      'profiles',
      'themes',
    ];

    // Required for unit testing
    foreach ($dirs as $dir) {
      if (!$fs->exists($drupalRoot . '/'. $dir)) {
        $fs->mkdir($drupalRoot . '/'. $dir);
        $fs->touch($drupalRoot . '/'. $dir . '/.gitkeep');
      }
    }

    // Prepare the settings file for installation
    if (!$fs->exists($drupalRoot . '/sites/default/settings.php') and $fs->exists($drupalRoot . '/sites/default/default.settings.php')) {
      $fs->copy($drupalRoot . '/sites/default/default.settings.php', $drupalRoot . '/sites/default/settings.php');
      require_once $drupalRoot . '/core/includes/bootstrap.inc';
      require_once $drupalRoot . '/core/includes/install.inc';
 /*     $settings['config_directories'] = [
        CONFIG_SYNC_DIRECTORY => (object) [
          'value' => Path::makeRelative($drupalFinder->getComposerRoot() . '/config/sync', $drupalRoot),
          'required' => TRUE,
        ],
      ];
*/
      // drupal_rewrite_settings($settings, $drupalRoot . '/sites/default/settings.php');
      $fs->chmod($drupalRoot . '/sites/default/settings.php', 0666);
      $event->getIO()->write("Create a sites/default/settings.php file with chmod 0666");
    }

    // Create the files directory with chmod 0777
    if (!$fs->exists($drupalRoot . '/sites/default/files')) {
      $oldmask = umask(0);
      $fs->mkdir($drupalRoot . '/sites/default/files', 0777);
      umask($oldmask);
      $event->getIO()->write("Create a sites/default/files directory with chmod 0777");
    }
  }

  /**
   * Checks if the installed version of Composer is compatible.
   *
   * Composer 1.0.0 and higher consider a `composer install` without having a
   * lock file present as equal to `composer update`. We do not ship with a lock
   * file to avoid merge conflicts downstream, meaning that if a project is
   * installed with an older version of Composer the scaffolding of Drupal will
   * not be triggered. We check this here instead of in drupal-scaffold to be
   * able to give immediate feedback to the end user, rather than failing the
   * installation after going through the lengthy process of compiling and
   * downloading the Composer dependencies.
   *
   * @see https://github.com/composer/composer/pull/5035
   */
  public static function checkComposerVersion(Event $event) {
    $composer = $event->getComposer();
    $io = $event->getIO();

    $version = $composer::VERSION;

    // The dev-channel of composer uses the git revision as version number,
    // try to the branch alias instead.
    if (preg_match('/^[0-9a-f]{40}$/i', $version)) {
      $version = $composer::BRANCH_ALIAS_VERSION;
    }

    // If Composer is installed through git we have no easy way to determine if
    // it is new enough, just display a warning.
    if ($version === '@package_version@' || $version === '@package_branch_alias_version@') {
      $io->writeError('<warning>You are running a development version of Composer. If you experience problems, please update Composer to the latest stable version.</warning>');
    }
    elseif (Comparator::lessThan($version, '1.0.0')) {
      $io->writeError('<error>Drupal-project requires Composer version 1.0.0 or higher. Please update your Composer before continuing</error>.');
      exit(1);
    }
  }

  /**
   * Apply patches to the installed packages.
   * 
   * This function enables the use of patches found in multiple compoer.json files to be applied.
   * Note naming convention for composer files should follow: composer_{custom name}.json
   */


  public static function applyPatches(Event $event) {
    $rootDir = getcwd();
    $patches = [];

    foreach (glob($rootDir . '/composer_*.json') as $composerFile) {
      self::processComposerFile($event, $composerFile, $patches);
    }

    // Apply the patches
    foreach ($patches as $packageDir => $patchInfo) {
      foreach ($patchInfo as $description => $patchFile) {
        self::applyPatch($event, $packageDir, $patchFile, $description);
      }
    }

    $event->getIO()->write("Patching process complete.");
  }

  /**
   * Process the composer.json file to extract the patches.
   * 
   */

  private static function processComposerFile(Event $event, $composerFilePath, &$patches) {
    if (!file_exists($composerFilePath)) {
      $event->getIO()->write("Composer file not found: {$composerFilePath}");
      return;
    }

    // Read and decode the composer.json (or composer_site.json) file
    $composerData = json_decode(file_get_contents($composerFilePath), true);
     
    if (isset($composerData['extra']['patches'])) {
      foreach ($composerData['extra']['patches'] as $packageName => $packagePatches) {
        foreach ($packagePatches as $patchDescription => $patchUrl) {
          
          $patches[$packageName][$patchDescription] = $patchUrl;
        }
      }
    }
  }

  /**
   * Apply the patch to the package.
   * 
   */

  private static function applyPatch(Event $event, $packageDir, $patchUrl, $description) {
    $event->getIO()->write("Applying patch: {$description}");

    // Define a temporary file to store the patch
    $tempPatchFile = tempnam(getcwd(), 'patch');
    $patchContents = file_get_contents($patchUrl);

    file_put_contents($tempPatchFile, $patchContents);


    //From the github url, extract the package name
    $package = explode('/', $packageDir);
    $package = end($package);

    //check if patch has already been applied
    $command = sprintf('patch -R -p1 --dry-run -d web/modules/contrib/%s < %s', $package, $tempPatchFile);
    exec($command, $output, $returnCode);

    if ($returnCode === 0) {
      $event->getIO()->write("Patch already applied: {$description}");
      unlink($tempPatchFile);
      return;
    }

    // print patch file name
    $command = sprintf('patch -d web/modules/contrib/%s < %s', $package, $tempPatchFile);

    // Execute the command

    $event->getIO()->write("Executing command: {$command}");
    exec($command, $output, $returnCode);

    // Check if the patch was applied successfully
    if ($returnCode !== 0) {
      $event->getIO()->write("Failed to apply patch: {$description}");
      return;
    }

    $event->getIO()->write("Patch applied successfully: {$description}");

    // Clean up the temporary patch file
    unlink($tempPatchFile);


  }

}
