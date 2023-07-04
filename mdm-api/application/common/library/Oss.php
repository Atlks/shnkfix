<?php


namespace app\common\library;

use app\common\model\Config;
use OSS\OssClient;
use OSS\Core\OssException;

class Oss
{
    protected $config;

    public function __construct($config=[])
    {
//        if(empty($config)){
//            $this->config=config('oss');
//        }else{
            $this->config=$config;
//        }
    }

    /**
     * OSS下载文件
     * @param string $object
     * @param string $savePath
     * @return bool
     */
    public function ossDownload($object = '', $savePath = '')
    {
        $dir = pathinfo($savePath, PATHINFO_DIRNAME);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $options = array(
            OssClient::OSS_FILE_DOWNLOAD => $savePath
        );
        try {
            $ossClient = new OssClient($this->config['key'], $this->config['secret'], $this->config['endpoint']);
            $ossClient->getObject($this->config['bucket'], $object, $options);
            return true;
        } catch (OssException $e) {
            return false;
        }
    }

    /**
     * OSS存储上传
     * @param string $filePath
     * @param string $saveName
     * @return bool
     */
    public function ossUpload($filePath = '', $saveName = '')
    {
        try {
            $ossClient = new OssClient($this->config['key'], $this->config['secret'], $this->config['endpoint']);
            $ossClient->uploadFile($this->config['bucket'], $saveName, $filePath);
        } catch (OssException $e) {
            return false;
        }
        return true;
    }

    /**
     * 删除云存储文件
     * @param string $saveName
     * @return bool
     */
    public function ossDelete($saveName = ''){
        try {
            $ossClient = new OssClient($this->config['key'], $this->config['secret'], $this->config['endpoint']);
            $ossClient->deleteObject($this->config['bucket'], $saveName);
        } catch (OssException $e) {
            return false;
        }
        return true;
    }

    /**
     * 获取文件列表
     * @param string $prefix
     * @return array|bool
     */
    public function listFile($prefix=''){
        try {
            $ossClient = new OssClient($this->config['key'], $this->config['secret'], $this->config['endpoint']);
            $list = $ossClient->listObjects($this->config['bucket'],['prefix'=>$prefix])
                    ->getObjectList();
            $listFiles = [];
            foreach ($list as $v){
                $listFiles[]=$v->getKey();
            }
        } catch (OssException $e) {
            return false;
        }
        return $listFiles;
    }

    /**
     * 是否存在
     * @param string $object
     * @return bool
     */
    public function isExitFile($object=''){
        try{
            $ossClient = new OssClient($this->config['key'], $this->config['secret'], $this->config['endpoint']);

            $exist = $ossClient->doesObjectExist($this->config['bucket'], $object);
            return $exist;
        } catch(OssException $e) {
            return false;
        }
    }

    /**
     * 获取上传凭证
     * @return array
     */
    public function policy(){
        $dir = 'cache-uploads/'.date("Ymd")."/";          // 用户上传文件时指定的前缀。
        $now = time();
        $expire = 30;  //设置该policy超时时间是10s. 即这个policy过了这个有效时间，将不能访问。
        $end = $now + $expire;
        $expiration = $this->gmt_iso8601($end);
        $ali_upload_max_size = Config::where("name","ali_upload_max_size")->value("value");
        //最大文件大小.用户可以自己设置
        $condition = array(0=>'content-length-range', 1=>0, 2=>$ali_upload_max_size*1024*1024);
        $conditions[] = $condition;

        // 表示用户上传的数据，必须是以$dir开始，不然上传会失败，这一步不是必须项，只是为了安全起见，防止用户通过policy上传到别人的目录。
        $start = array(0=>'starts-with', 1=>'$key', 2=>$dir);
        $conditions[] = $start;
        $arr = array('expiration'=>$expiration,'conditions'=>$conditions);
        $policy = json_encode($arr);
        $base64_policy = base64_encode($policy);
        $string_to_sign = $base64_policy;
        $signature = base64_encode(hash_hmac('sha1', $string_to_sign, $this->config['secret'], true));

        $response = array();
        $response['accessid'] = $this->config['key'];
        $response['host'] = "https://".$this->config["bucket"].".".$this->config["endpoint"]."/";
//        $response['host'] = "https://".$this->config["own_endpoint"]."/";
        $response['policy'] = $base64_policy;
        $response['signature'] = $signature;
        $response['expire'] = $end;
        $response['dir'] = $dir;  // 这个参数是设置用户上传文件时指定的前缀。
        return $response;
    }

    protected  function  gmt_iso8601($time) {
        $dtStr = date("c", $time);
        $mydatetime = new \DateTime($dtStr);
        $expiration = $mydatetime->format(\DateTime::ISO8601);
        $pos = strpos($expiration, '+');
        $expiration = substr($expiration, 0, $pos);
        return $expiration."Z";
    }

    public function signUrl($url,$timeout=30){
        try{
            $ossClient = new OssClient($this->config['key'], $this->config['secret'], $this->config['own_endpoint'],true);
            $ossClient->setUseSSL(true);
            $signUrl = $ossClient->signUrl($this->config["bucket"],$url,$timeout);
            return $signUrl;
        } catch(OssException $e) {
            return false;
        }
    }

    public function oss_url(){
        return $this->config["url"];
    }


}