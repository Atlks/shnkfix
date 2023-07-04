<?php


namespace app\common\library;

use Google\Cloud\Storage\StorageClient;


class GoogleOss
{

    protected $storage;
    protected $bucket;

    protected $projectId ;

    public $bucket_name;

    public $cdn;

    protected $keyFile;

    public function __construct($is_new=false)
    {
        //TODO::线上打开此注释
        if($is_new){
            $this->new_init();
        }else{
            $this->init();
        }
        $this->storage = new StorageClient(['projectId' => $this->projectId, 'keyFile' => $this->keyFile]);
        $this->bucket = $this->storage->bucket($this->bucket_name);
    }

    public function init(){
        $this->projectId = "8QyD5vQLir0QKCjIxtK8yneibFQ7y7wl+z7WOSk5";
        $this->bucket_name = "qkmapp";
        $this->cdn = "https://storage.googleapis.com/qkmapp/";
        $this->keyFile = json_decode(file_get_contents(ROOT_PATH . "extend/qkm-google.json"), true);
    }

    public function new_init(){
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

    /**
     * 是否存在
     * @param $path
     * @return bool
     */
    public function isExit($path){
        $object = $this->bucket->object($path);
        if ($object->exists()) {
            return true;
        }else{
            return false;
        }
    }
    public function isExitFile($path){
        $object = $this->bucket->object($path);
        if ($object->exists()) {
            return true;
        }else{
            return false;
        }
    }

    public function oss_url(){
        return $this->cdn;
    }

    public function signUrl($oss_path){
        $object = $this->bucket->object($oss_path);
        return $object->signedUrl(new \DateTime('1 min'), ['version' => 'v4',]);
    }

    /***
     * 私有加签
     * @param $oss_path
     * @param $is_new
     * @return string
     */
    public static function privateSignUrl($oss_path,$is_new=false){
        if($is_new){
            $projectId = "8QyD5vQLir0QKCjIxtK8yneibFQ7y7wl+z7WOSk5";
            $bucket_name = "qkmapp";
            $cdn = "https://storage.googleapis.com/qkmapp/";
            $keyFile = json_decode(file_get_contents(ROOT_PATH . "extend/qkm-google.json"), true);
        }else{
            $projectId = "zD3woAYFWjGobrX0dieAtq919dFz7Ljlog+B9xOe";
            $bucket_name = "qkapp";
            $cdn = "https://storage.googleapis.com/qkapp/";
            $keyFile = json_decode(file_get_contents(ROOT_PATH . "extend/google-cloud.json"), true);
        }
        $storage = new StorageClient(['projectId' => $projectId, 'keyFile' => $keyFile]);
        $bucket = $storage->bucket($bucket_name);
        $object = $bucket->object($oss_path);
        return $object->signedUrl(new \DateTime('1 min'), ['version' => 'v4',]);
    }

}