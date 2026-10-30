<?php

namespace ComposerManifest;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\EventDispatcher\Event as BaseEvent;
use Symfony\Component\Yaml\Yaml;

/**
 * Composer manifest plugin.
 */
class Plugin implements PluginInterface, EventSubscriberInterface, Capable {

  /**
   * @var \Composer\Composer $composer
   */
  protected $composer;

  /**
   * @var \Composer\IO\IOInterface $io
   */
  protected $io;

  /**
   * @param \Composer\Composer $composer
   * @param \Composer\IO\IOInterface $io
   */
  public function activate(Composer $composer, IOInterface $io) {
    // Development: this makes symfony var-dumper work.
    // See https://github.com/composer/composer/issues/7911
    // include './vendor/symfony/var-dumper/Resources/functions/dump.php';

    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * {@inheritdoc}
   */
  public function getCapabilities() {
    return array(
      'Composer\Plugin\Capability\CommandProvider' => CommandProvider::class,
    );
  }

  /**
   * Returns an array of event names this subscriber wants to listen to.
   */
  public static function getSubscribedEvents() {
    return array(
      ScriptEvents::POST_INSTALL_CMD => array('updateManifest'),
      ScriptEvents::POST_UPDATE_CMD => array('updateManifest'),
      PackageEvents::POST_PACKAGE_UNINSTALL => array('updateManifest', 10),
    );
  }


  /**
   * Gets the list of package names and formatted versions.
   *
   * @return array
   */
  public function getPackageVersions() {
    $repositoryManager = $this->composer->getRepositoryManager();
    $localRepository = $repositoryManager->getLocalRepository();
    $packages = $localRepository->getPackages();

    if (empty($packages) && $this->composer->getLocker() && $this->composer->getLocker()->isLocked()) {
      $packages = $this->composer->getLocker()->getLockedRepository(true)->getPackages();
    }

    $packages = array_filter($packages, function ($package) {
      // Only include real packages, not their aliases. This is to prevent
      // inconsistent $packages order between install/require/update operations
      // from causing the manifest output to switch between the version string
      // of the original and the alias.
      return !($package instanceof AliasPackage);
    });

    $package_versions = array();
    foreach ($packages as $package) {
      $pretty_version = $package->getFullPrettyVersion(FALSE);
      // For backwards compatibility use ':' instead of space to separate
      // friendly name from revision hash.
      $output_version = str_replace(' ', ':', $pretty_version);
      $package_versions[$package->getName()] = $output_version;
    }

    // Make sure the packages are sorted consistently. We need this because in
    // some cases, new packages are at the end of the list returned by
    // getPackages() rather than in their correct place in the alphabetical
    // order: WTF.
    ksort($package_versions);

    return $package_versions;
  }

  /**
   * Rewrites the manifest YAML file.
   *
   * @param \Composer\EventDispatcher\Event $event
   */
  public function updateManifest(BaseEvent $event) {
    $this->generateManifest();
  }

  /**
   * Generates or regenerates the manifest YAML file.
   *
   * @param string $filename
   */
  public function generateManifest($filename = 'composer-manifest.yaml') {
    $package_versions = $this->getPackageVersions();

    $yaml_data = [
      // 'content-hash' => $content_hash,
      'packages' => $package_versions,
    ];

    $yaml = Yaml::dump($yaml_data);
    file_put_contents($filename, $yaml);
  }


  /**
   * {@inheritdoc}
   */
  public function deactivate(Composer $composer, IOInterface $io) {
    // Do nothing.
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(Composer $composer, IOInterface $io) {
    if (file_exists('composer-manifest.yaml')) {
      unlink('composer-manifest.yaml');
    }
  }
}
