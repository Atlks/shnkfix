<?php


namespace app\library;


use Google\Cloud\Storage\StorageClient;

class GoogleOss
{
    protected $storage;
    protected $bucket;

    protected $projectId;

    protected $bucket_name;

    public $cdn;

    protected $keyFile;

    public function __construct()
    {
        //TODO::线上打开此注释
        $this->init();
        $this->storage = new StorageClient(['projectId' => $this->projectId, 'keyFile' => $this->keyFile]);
        $this->bucket = $this->storage->bucket($this->bucket_name);
    }

    public function init()
    {
        $this->projectId = "8QyD5vQLir0QKCjIxtK8yneibFQ7y7wl+z7WOSk5";
        $this->bucket_name = "qkmsign";
        $this->cdn = "https://storage.googleapis.com/qkmsign/";
        $this->keyFile = json_decode(file_get_contents(ROOT_PATH . "extend/qkm-google.json"), true);

    }


    /**
     * 上传谷歌云
     * @param $path
     * @param $savePath
     * @return bool
     */
    public function ossUpload($path, $savePath)
    {
        if (!is_file($path)) return false;
        $result = $this->bucket->upload(fopen($path, 'r'), ['name' => $savePath]);
        if ($result->exists()) {
            return $savePath;
        } else {
            return false;
        }
    }

    /**
     * 下载资源
     * @param $path
     * @param $savePath
     * @return bool
     */
    public function ossDownload($path, $savePath)
    {
        $object = $this->bucket->object($path);
        if ($object->exists()) {
            $dir = pathinfo($savePath, PATHINFO_DIRNAME);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $object->downloadToFile($savePath);
            return true;
        } else {
            return false;
        }
    }

}