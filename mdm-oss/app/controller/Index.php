<?php

namespace app\controller;

use app\BaseController;
use app\model\OssConfig;
use app\model\ProxyApp;
use app\library\GoogleOss;
use app\library\Oss;
use app\library\Redis;
use think\facade\Log;

class Index extends BaseController
{

    public function fff()
    {
        echo "fffffff";
    }
    public function index()
    {
        echo "";
    }

    public function upload()
    {
        return json(["code" => 1]);
        $host = "https://" . request()->host();
        $method = request()->method();
        if ($method == "OPTIONS") {
            return json(["code" => 1]);
        }
        $result = [
            "code" => 1,
            "data" => ['url' => $host, 'fullurl' => $host],
            "msg" => "上传成功",
            "url" => $host,
            "wait" => 3,
        ];
        // 获取表单上传文件 例如上传了001.jpg
        $file = request()->file('file');
        if (empty($file)) {
            $result["code"] = 0;
            $result["msg"] = "上传失败，文件不存在";
            return json($result);
        }
        // 上传到本地服务器
        $save_path = public_path() . "apk/" . date("Y-m-d");
        $save_name = $file->md5() . "." . $file->extension();
        $file->move($save_path, $save_name);
        $file_path = $save_path . "/" . $save_name;
        if (is_file($file_path)) {
            //upload ok
            $apk_url = $host . "/apk/" . date("Y-m-d") . "/" . $save_name;
            $result["url"] = $apk_url;
            $result["data"] = ['url' => $apk_url, 'fullurl' => $apk_url];
            return json($result);
        }
        unlink($file_path);
        $result["code"] = 0;
        $result["msg"] = "上传失败";
        return json($result)->code(200)->allowCache(true);
    }

    public function apk_del(){
        $key = $this->request->post("key", "");
        $sign = $this->request->post("sign", "");
        $md5_str = "8^6kJ6MYz_7SwPLZlxeC";
        if (md5($key . $md5_str) != $sign) {
            return json(["code" => 1]);
        }
        $file = public_path().$key;
        if(is_file($file)){
            @unlink($file);
        }
        return json(["code" => 1]);
    }

    public function f_auth(){
        $params = $this->request->param();
//        if(empty($params["p1"])||empty($params["p2"])||empty($params["Expires"])||empty($params["Signature"])){
//            header('HTTP/1.1 404');
//            exit();
//        }
//        $key = "yub7STybT6gtDF3wiDjDvaPX7cIeGp";
//        $sign = $params["Signature"];
//        $new_sign = sha1($key.'apk/'.$params["p1"]."/".$params["p2"].$params["Signature"]);
//        if($sign==$new_sign){
            if(is_file(public_path() ."apk/".$params["p1"]."/".$params["p2"])) {
                header("Content-Type:application/octet-stream;");
                header("Content-Disposition:attachment;filename=" . $params["p2"]);
                header("X-Accel-Redirect: /oss/apk/" . $params["p1"] . "/" . $params["p2"]);
            }else{

                header('HTTP/1.1 404');
                exit();
            }
//        }else{
//            header('HTTP/1.1 404');
//            exit();
//        }
    }

    //指定图片从oss下载同步到本地服务器
    public function img_async()
    {
        //oss存储路径
        $path = $this->request->post('path', '');
        if(empty($path)) return json(['code' => 0]);
        $google_oss = new GoogleOss();
        //本地存储路径
        $save_path=ROOT_PATH .'public/'.$path;
        if(!is_file($save_path)){
            //下载到本地
            if ($google_oss->ossDownload($path,$save_path)) {
                return json(['code' => 200]);
            }else{
                return json(['code' => 0]);
            }
        }
        return json(['code' => 200]);
    }

    //全部图片从oss下载同步到本地服务器
    public function img_all_async()
    {
        $google_oss = new GoogleOss();
        $app_id=0;
        //从redis内获取上次同步到的app_id
        //$cache_app_id=Redis::hGetAll('img_async_app_id',15);
        //$app_id=$cache_app_id['app_id'];
        while(true){
            $app_list = ProxyApp::where('id', '>',$app_id)
                ->limit(50)
                ->field('id,icon,imgs,download_bg')
                ->order('id','asc')
                ->select();
            if(empty($app_list)) break;
            foreach($app_list as &$v){
                //截图
                if (!empty($v['imgs'])) {
                    $imgs = array_filter(explode(',', $v['imgs']));
                    foreach ($imgs as $img) {
                        //oss存储路径
                        $path=substr($img, strpos($img, 'upload/'));
                        //本地存储路径
                        $save_img=ROOT_PATH .'public/'.$path;
                        if(is_file($save_img)) continue;
                        //下载到本地
                        if ($google_oss->ossDownload($path,$save_img)) {
                            continue;
                        }else{
                            Log::error('同步app_id('.$v['id'].')应用截图失败，地址：'.$img);
                        }
                    }
                    $imgs=null;
                    $img=null;
                    $save_img=null;
                }
                //应用icon图标
                if (!empty($v['icon'])) {
                    //oss存储路径
                    $icon=substr($v['icon'], strpos($v['icon'], 'upload/'));
                    //本地存储路径
                    $save_icon=ROOT_PATH .'public/'.$icon;
                    if(!is_file($save_icon)){
                        //下载到本地
                        if ($google_oss->ossDownload($icon,$save_icon)) {
                            continue;
                        }else{
                            Log::error('同步app_id('.$v['id'].')icon图标失败，地址：'.$v['icon']);
                        }
                    }
                    $icon=null;
                    $save_icon=null;
                }
                //下载背景图
                if (!empty($v['download_bg'])) {
                    //oss存储路径
                    $download_bg=substr($v['download_bg'], strpos($v['download_bg'], 'upload/'));
                    //本地存储路径
                    $save_download_bg=ROOT_PATH .'public/'.$download_bg;
                    if(!is_file($save_download_bg)){
                        //下载到本地
                        if ($google_oss->ossDownload($download_bg,$save_download_bg)) {
                            continue;
                        }else{
                            Log::error('同步app_id('.$v['id'].')下载背景图失败，地址：'.$v['download_bg']);
                        }
                    }
                    $download_bg=null;
                    $save_download_bg=null;
                }
                //redis存储已同步app_id
                //Redis::hMSet('img_async_app_id',['app_id'=>$v['id']],15);
            }
            $app_id=$app_list[count($app_list)-1]['id'];
            Log::info('图片同步执行到应用，app_id:'.$app_id);
            if(count($app_list)<50) break;
            $app_list=null;
            $v=null;
        }
    }

    //非永久保存文件上传上传
    public function cache_upload()
    {
        $key = $this->request->post("key", "");
        $sign = $this->request->post("sign", "");
        $md5_str = "uSl!I~vGjYQHJUXjTxUO";
        if (md5($key . $md5_str) != $sign) {
            return json(["code" => 0]);
        }
        $info = pathinfo($key);
        if (empty($info["dirname"]) || empty($info["basename"])) {
            return json(["code" => 0]);
        }
        $save_path = public_path() . $info["dirname"];
        $save_name = $info["basename"];
        if(!is_dir($save_path."/")){
            mkdir($save_path."/",0777,true);
        }
        $host = "https://" . request()->host();
        $method = request()->method();
        if ($method == "OPTIONS") {
            return json(["code" => 1]);
        }
        $result = [
            "code" => 200,
            "msg" => "上传成功",
            "url" => $host,
            "wait" => 3,
            "key" =>  $save_name,
        ];
        // 获取表单上传文件 例如上传了001.jpg
        $file = request()->file('file');
        if (empty($file)) {
            $result["code"] = 0;
            $result["msg"] = "上传失败，文件不存在";
            return json($result);
        }
        // 上传到本地服务器
        $file->move($save_path, $save_name);
        $file_path = $save_path . "/" . $save_name;
        if (is_file($file_path)) {
            return json($result);
        }
        $result["code"] = 0;
        $result["msg"] = "上传失败";
        return json($result)->code(200)->allowCache(true);
    }
}
