<?php

namespace SilverStripe\Assets\Flysystem;

use Exception;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Config as FlysystemConfig;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Templates\Viewer;

/**
 * Adapter for local filesystem based on assets directory
 */
class AssetAdapter extends Local
{
    use Configurable;

    /**
     * Server specific configuration necessary to block http traffic to a local folder
     *
     * @config
     * @var array Mapping of server configurations to configuration files necessary
     */
    private static $server_configuration = array();

    /**
     * Default server configuration to use if the server type defined by the environment is not found
     *
     * @config
     * @var string
     */
    private static $default_server = 'apache';

    /**
     * Config compatible permissions configuration
     *
     * @config
     * @var array
     */
    private static $file_permissions = array(
        'file' => [
            'public' => 0664,
            'private' => 0600,
        ],
        'dir' => [
            'public' => 0775,
            'private' => 0700,
        ]
    );

    public function __construct($root = null, $writeFlags = LOCK_EX, $linkHandling = self::DISALLOW_LINKS)
    {
        // Get root path, and ensure that this exists and is safe
        $root = $this->findRoot($root);
        Filesystem::makeFolder($root);
        $root = realpath($root);

        // Override permissions with config
        $permissions = $this->normalisePermissions($this->config()->get('file_permissions'));
        parent::__construct($root, $writeFlags, $linkHandling, $permissions);

        // Configure server
        $this->configureServer();
    }

    /**
     * Converts strings to octal permission codes. E.g. '0700' => 0700
     *
     * @param array $config
     * @return array
     */
    public static function normalisePermissions($config)
    {
        foreach ($config as $type => $codes) {
            foreach ($codes as $key => $mask) {
                if (is_string($mask)) {
                    $config[$type][$key] = intval($mask, 8);
                }
            }
        }
        return $config;
    }

    /**
     * Determine the root folder absolute system path
     *
     * @param string $root
     * @return string
     */
    protected function findRoot($root)
    {
        // Empty root will set the path to assets
        if (!$root) {
            throw new \InvalidArgumentException("Missing argument for root path");
        }

        // Substitute leading ./ with BASE_PATH
        if (strpos($root, './') === 0) {
            return BASE_PATH . substr($root, 1);
        }

        // Substitute leading ./ with parent of BASE_PATH, in case storage is outside of the webroot.
        if (strpos($root, '../') === 0) {
            return dirname(BASE_PATH) . substr($root, 2);
        }

        return $root;
    }

    /**
     * Force flush and regeneration of server files
     */
    public function flush()
    {
        $this->configureServer(true);
    }

    /**
     * Configure server files for this store
     *
     * @param bool $forceOverwrite Force regeneration even if files already exist
     * @throws Exception
     */
    protected function configureServer($forceOverwrite = false)
    {
        // Get server type
        $type = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '*';
        list($type) = explode('/', strtolower($type));

        // Determine configurations to write
        $rules = $this->config()->get('server_configuration');
        if (empty($rules[$type])) {
            $type = $this->config()->get('default_server');
            if (!$type || empty($rules[$type])) {
                return;
            }
        }
        $configurations = $rules[$type];

        $visibility = 'public';

        // Apply each configuration
        $config = new FlysystemConfig();
        $config->set('visibility', $visibility);
        foreach ($configurations as $file => $template) {
            // Ensure file contents
            if ($forceOverwrite || !$this->has($file)) {
                // Evaluate file
                $content = $this->renderTemplate($template);
                $success = $this->write($file, $content, $config);
                if (!$success) {
                    throw new Exception("Error writing server configuration file \"{$file}\"");
                }
            }
            $perms = $this->getVisibility($file);
            if ($perms['visibility'] !== $visibility) {
                // Ensure correct permissions
                $this->setVisibility($file, $visibility);
            }
        }
    }

    /**
     * Render server configuration file from a template file
     *
     * @param string $template
     * @return string Rendered results
     */
    protected function renderTemplate($template)
    {
        // Build allowed extensions
        $allowedExtensions = new ArrayList();
        foreach (File::config()->allowed_extensions as $extension) {
            if ($extension) {
                $allowedExtensions->push(new ArrayData(array(
                    'Extension' => preg_quote($extension)
                )));
            }
        }

        Config::nest();
        Config::modify()->set(Viewer::class, 'source_file_comments', false);

        $viewer = Viewer::create(array($template));
        $result = (string)$viewer->process(new ArrayData(array(
            'AllowedExtensions' => $allowedExtensions
        )));

        Config::unnest();

        return $result;
    }
}
