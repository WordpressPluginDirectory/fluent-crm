<?php

namespace FluentCrm\App\Services\Libs;

class FileSystem
{
    /**
     * Read file content from custom upload dir of this application
     * @return string [path]
     */
    public function _get($file)
    {
        return file_get_contents($this->getSafeUploadFilePath($file));
    }

    /**
     * Get custom upload dir name of this application
     * @return string [directory path]
     */
    public function _getDir()
    {
        $uploadDir = wp_upload_dir();

        $fluentCrmUploadDir = apply_filters('fluent_crm/upload_folder_name', FLUENTCRM_UPLOAD_DIR);

        return $uploadDir['basedir'] . $fluentCrmUploadDir;
    }

    /**
     * Get absolute path of file using custom upload dir name of this application
     * @return string [file path]
     */
    public function _getAbsolutePathOfFile($file)
    {
        return $this->getSafeUploadFilePath($file);
    }

    /**
     * Upload files into custom upload dir of this application
     * @return array
     */
    public function _uploadFromRequest()
    {
        return $this->_put(FluentCrm('request')->files());
    }

    /**
     * Upload files into custom upload dir of this application
     * @param array $files
     * @return array
     */
    public function _put($files)
    {
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $this->overrideUploadDir();

        $uploadOverrides = ['test_form' => false];

        foreach ((array)$files as $file) {
            $filesArray = $file->toArray();
            $uploadedFiles[] = \wp_handle_upload($filesArray, $uploadOverrides);
        }

        return $uploadedFiles;
    }

    /**
     * Delete a file from custom upload directory of this application
     * @param array $files
     * @return void
     */
    public function _delete($files)
    {
        $files = (array)$files;

        foreach ($files as $file) {
            wp_delete_file($this->getSafeUploadFilePath($file));
        }
    }

    /**
     * Resolve only the opaque filenames created by this class' upload flow.
     *
     * CSV import requests arrive after upload and therefore contain a client
     * supplied value. Requiring the generated prefix, a plain filename, and a
     * real path inside the dedicated directory prevents a later request from
     * turning that value into an arbitrary read or delete path.
     *
     * @param mixed $file Upload filename returned by _put().
     * @return string Absolute path to a regular file in the FluentCRM upload directory.
     * @throws \InvalidArgumentException When the filename is not a valid uploaded CSV token.
     */
    private function getSafeUploadFilePath($file)
    {
        if (!is_string($file)
            || $file === ''
            || basename($file) !== $file
            || strpos($file, '/') !== false
            || strpos($file, '\\') !== false
            || strpos($file, '..') !== false
            || strpos($file, "\0") !== false
            || !preg_match('/^fluentcrm-[a-f0-9]{32}-fluentcrm-/', $file)
        ) {
            throw new \InvalidArgumentException(__('Invalid CSV import file.', 'fluent-crm'));
        }

        $directory = realpath($this->getDir());
        $path = $directory ? realpath($directory . DIRECTORY_SEPARATOR . $file) : false;

        if (!$directory
            || !$path
            || !is_file($path)
            || strpos($path, $directory . DIRECTORY_SEPARATOR) !== 0
        ) {
            throw new \InvalidArgumentException(__('Invalid CSV import file.', 'fluent-crm'));
        }

        return $path;
    }

    /**
     * Register filters for custom upload dir
     */
    public function _overrideUploadDir()
    {
        add_filter('wp_handle_upload_prefilter', function ($file) {
            add_filter('upload_dir', [$this, '_setCustomUploadDir']);

            add_filter('wp_handle_upload', function ($fileinfo) {
                remove_filter('upload_dir', [$this, '_setCustomUploadDir']);
                $fileinfo['file'] = basename($fileinfo['file']);
                return $fileinfo;
            });

            return $this->_renameFileName($file);
        });
    }

    /**
     * Set plugin's custom upload dir
     * @param array $param
     * @return array $param
     */
    public function _setCustomUploadDir($param)
    {

        $fluentCrmUploadDir = apply_filters('fluent_crm/upload_folder_name', FLUENTCRM_UPLOAD_DIR);

        $param['url'] = $param['baseurl'] . $fluentCrmUploadDir;

        $param['path'] = $param['basedir'] . $fluentCrmUploadDir;

        if (!is_dir($param['path'])) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
            mkdir($param['path'], 0755);
             file_put_contents(
                 $param['basedir'].$fluentCrmUploadDir.'/.htaccess',
                 file_get_contents(__DIR__.'/Stubs/htaccess.stub')
             );
        }

        if(!file_exists($param['basedir'].$fluentCrmUploadDir.'/index.php')) {
            file_put_contents(
                $param['basedir'].$fluentCrmUploadDir.'/index.php',
                file_get_contents(__DIR__.'/Stubs/index.stub')
            );
        }

        return $param;
    }

    /**
     * Rename the uploaded file name before saving
     * @param array $file
     * @return array $file
     */
    public function _renameFileName($file)
    {
        $prefix = 'fluentcrm-' . md5(wp_generate_uuid4()) . '-fluentcrm-';
        $file['name'] = $prefix . $file['name'];

        return $file;
    }

    public static function __callStatic($method, $params)
    {
        $instance = new static;

        return call_user_func_array([$instance, $method], $params);
    }

    public function __call($method, $params)
    {
        $hiddenMethod = "_" . $method;

        $method = method_exists($this, $hiddenMethod) ? $hiddenMethod : $method;

        return call_user_func_array([$this, $method], $params);
    }
}
