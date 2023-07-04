<?php

namespace app\api\controller;

use app\common\library\GoogleOss;
use app\common\library\Redis;
use app\common\model\AutoAppRefush;
use app\common\model\DownloadCode;
use app\common\model\DownloadUrl;
use app\common\model\Enterprise;
use app\common\model\OssConfig;
use app\common\model\ProxyAppApkDownloadLog;
use app\common\model\ProxyAppUpdateErrorLog;
use app\common\model\ProxyRechargeLog;
use app\common\library\Ip2Region;
use app\common\model\ProxyDownload;
use app\common\controller\Api;
use app\common\library\IosPackage;
use app\common\library\Oss;
use app\common\model\ProxyUser;
use app\common\model\ProxyApp;
use app\common\model\ProxyUserDomain;
use app\common\model\ProxyAppUpdateLog;
use app\common\model\ProxyResignFree;
use app\common\model\AppAuto;
use app\common\model\Config;
use app\common\model\appEarlyWarning;
use fast\Random;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use think\Db;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use think\Exception;
use think\Log;
use think\Validate;
use TelegramBot\Api\BotApi;

/**
 * app相关
 */
class App extends Api
{
    protected $noNeedLogin = [''];

    protected $noNeedRight = ['*'];

    public function init()
    {
        $ip = $this->request->ip();
        $host = $this->request->domain();
        if($host=="api.qksign88.com"){
            $host .=":7643";
        }
        $result = [
            'upload' => $host . '/api/common/ossToken',
            'ipaParsing' => $host . "/api/common/ipaParsing",
            'is_overseas' => '10',
            'ip' => $ip
        ];
        $ip2 = new Ip2Region();
        $ip_address = $ip2->binarySearch($ip);
        $address = explode('|', $ip_address['region']);
        if ($address[0] == "中国" && !in_array($address[2], ["澳门", '香港', "台湾省","台湾"])) {
            $result["is_overseas"] = 10;
        } else {
            $result["is_overseas"] = 20;
        }
        $this->result('初始化', $result, 200);
    }

    /**
     * app列表（代理）
     * @ApiWeigh 1
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=true, description="会员id")
     * @ApiParams      (name="page", type="integer", required=true, description="当前页")
     * @ApiParams      (name="page_size", type="integer", required=true, description="每页显示数")
     */
    public function app_list()
    {
        $id = $this->request->param('id', null);
        $keywords = $this->request->param('keywords', null);
        $ip = $this->request->ip();
        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 10);
        if (intval($page) < 1) $page = 1;
        $offset = ($page - 1) * $pageSize;
        $where = [
            "u.pid" => $this->auth->id,
            "a.is_delete" => 1,
            "a.is_admin" => 1,
            'u.status' => 'normal'
        ];
        if (!empty($id)) {
            $user_where = [
                "id" => $id,
                "status" => "normal",
                "pid" => $this->auth->id
            ];
            $info = ProxyUser::where($user_where)->find();
            if (!$info) {
                $this->error('会员不存在');
            }
            $where["u.id"] = $id;
        }
        if ($keywords) {
            $where['a.name'] = ['like', '%' . $keywords . '%'];
        }
        $ip2 = new Ip2Region();
        $ip_address = $ip2->binarySearch($ip);
        $address = explode('|', $ip_address['region']);
        if ($address[0] == "中国" && !in_array($address[2], ["澳门", '香港', "台湾省","台湾"])) {
            $public_url = Config::where('name', 'proxy_zh_oss_public_url')->value('value');
        } else {
            $public_url = Config::where('name', 'proxy_en_oss_public_url')->value('value');
        }
        $model = new ProxyApp();
        $total = $model->alias("a")
            ->join('proxy_user u', 'a.user_id = u.id')
            ->where($where)
            ->count();
        $list = $model->alias("a")
            ->join('proxy_user u', 'a.user_id = u.id')
            ->where($where)
            ->order('create_time', 'desc')
            ->limit($offset, $pageSize)
            ->column('a.id,a.name,a.user_id,a.create_time,a.icon,a.version_code,a.filesize,a.status,u.username,a.short_url,a.is_stop,a.mode');
        $bale_rate_table = getTable("proxy_bale_rate", $this->auth->id);
        $proxy = ProxyUserDomain::get(['user_id' => $this->auth->id]);
        /***获取配置URL**/
        $host = $proxy["download_url"];
        $port_data = DownloadUrl::where("name",$host)
            ->where("status",1)
            ->cache(true,180)
            ->find();
        if(empty($port_data)){
            $port_data = DownloadUrl::where("status",1)
                ->where("is_default")
                ->cache(true,180)
                ->find();
        }

        foreach ($list as $k=>$v) {
            /**带端口**/
            if(!empty($port_data["web_port"])){
                $ports = explode(",",$port_data["web_port"]);
                $port = $ports[array_rand($ports)];
                $url ="$host:$port";
            }else{
                $url = $host;
            }
//            $list[$k]['url'] = 'https://' . $url.'/' . $v['short_url'] . '.html';
            $list[$k]['url'] = 'https://' . $url.'/' . $v['short_url'] ;
            $list[$k]['filesize'] = format_bytes($v['filesize']);
            $list[$k]['name'] = htmlspecialchars_decode($v["name"]);
            $list[$k]["download_num"] = Db::table($bale_rate_table)->where("user_id", $v["user_id"])
                ->where("app_id", $v["id"])
                ->where("status", 1)
                ->cache(true,300)
                ->count();
            $list[$k]["icon"] = $public_url."/" . substr($v["icon"], strpos($v["icon"], 'upload/'));
            /**此处反向操作**/
            if($v["is_stop"]==0){
                $list[$k]["status"]=1;
            }else{
                $list[$k]["status"]=0;
            }
        }
        $result = [
            'total' => $total,
            'currentPage' => $page,
            'list' => array_values($list)
        ];
        $this->result('success', $result, 200);
    }

    /**
     * 管理端修改模式
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function super_edit_app(){
        $app_id = $this->request->param('app_id', null);
        $mode = $this->request->param('mode', null);
        if(empty($app_id)||empty($mode)){
            $this->error('缺少参数');
        }
        $info = ProxyApp::where("id",$app_id)
            ->where("is_delete",1)
            ->where("is_download",0)
            ->find();
        if (empty($info)) {
            $this->error('应用不存在');
        }
        $user_where = [
            "id" => $info["user_id"],
            "status" => "normal",
            "pid" => $this->auth->id
        ];
        $user = ProxyUser::where($user_where)->find();
        if (empty($user)) {
            $this->error('会员不存在');
        }
        $update=[
            "id"=>$app_id,
            "mode"=>intval($mode)
        ];
        if(ProxyApp::update($update)){
            /**
             * @todo APP缓存清理
             */
            Redis::del("app_tag:".$info["tag"],0);
            Redis::del("app_tag:".$info["tag"],4);
            Redis::del("app_short_url:".$info["short_url"],4);
            $this->success("修改成功",null,200);
        }else{
            $this->error("修改失败，请稍后再试");
        }
    }


    /**
     * 我的应用列表(会员)
     * @ApiWeigh  1
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="keywords", type="String", required=false, description="搜索关键词")
     * @ApiParams   (name="type", type="Int", required=false, description="数据类型1-消费2-浏览3-下载4-设备默认1")
     * @ApiParams   (name="page", type="Int", required=false, description="当前页")
     * @ApiParams   (name="page_size", type="Int", required=false, description="每页显示条数")
     * @ApiReturnParams (name="status", type="integer", required=true, description="状态：1正常0关闭")
     * @ApiReturnParams (name="is_delete", type="integer", required=true, description="删除：1正常0删除")
     * @ApiReturnParams (name="rate", type="String", required=true, description="服务单价")
     * @ApiReturnParams (name="download_num", type="Int", required=true, description="下载量")
     * @ApiReturnParams (name="total", type="Int", required=true, description="应用总条数")
     * @ApiReturnParams (name="currentPage", type="Int", required=true, description="当前页")
     * @ApiReturnParams (name="type", type="Int", required=true, description="类型1-超级签名2-企业签名")
     */
    public function appList()
    {
        $keywords = $this->request->post('keywords');
        $type = $this->request->post('type');
        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 10);
        $ip = $this->request->ip();
        if (intval($page) < 1) $page = 1;
        $offset = ($page - 1) * $pageSize;
        $where = [
            'user_id' => $this->auth->id,
            'is_delete' => 1,
            "is_admin" => 1,
            'is_download'=>0
        ];
        $user_id = $this->auth->id;
        $keywords && $where['name'] = ['like', '%' . $keywords . '%'];
        $user = ProxyUser::get($this->auth->id);

        if (!$user) {
            $this->result('success', [], 200);
        }

        $proxy = ProxyUserDomain::get(['user_id' => $user['pid']]);
        $total = ProxyApp::where($where)->count('id');
        $list = ProxyApp::where($where)
            ->order('create_time', 'desc')
            ->limit($offset, $pageSize)
            ->column('id,version_code,system_version,name,icon,type,package_name,status,tag,download_num,is_delete,create_time,update_time,apk_url,short_url,is_stop,remark');
        $ip2 = new Ip2Region();
        $ip_address = $ip2->binarySearch($ip);
        $address = explode('|', $ip_address['region']);
        if ($address[0] == "中国" && !in_array($address[2], ["澳门", '香港', "台湾省","台湾"])) {
            $public_url = Config::where('name', 'proxy_zh_oss_public_url')->value('value');
        } else {
            $public_url = Config::where('name', 'proxy_en_oss_public_url')->value('value');
        }
        $wx_url = Config::where("name", "proxy_wx_url")
            ->value("value");
        $bale_rate_table = getTable("proxy_bale_rate", $user["pid"]);
        $bale_rate_v1_table = getTable("proxy_v1_bale_rate", $user["pid"]);
        /***获取配置URL**/
        $host = $proxy["download_url"];
        $port_data = DownloadUrl::where("name",$host)
            ->where("status",1)
            ->cache(true,180)
            ->find();
        if(empty($port_data)){
            $port_data = DownloadUrl::where("status",1)
                ->where("is_default")
                ->cache(true,180)
                ->find();
        }
        foreach ($list as $k => $v) {
            /**带端口**/
            if(!empty($port_data["web_port"])){
                $ports = explode(",",$port_data["web_port"]);
                $port = $ports[array_rand($ports)];
                $url ="$host:$port";
            }else{
                $url = $host;
            }
            if ($proxy["ext"] == "app") {
                $list[$k]['url'] = 'https://' . $url.'/' . $v['tag'] . '.app';
            } else {
//                $list[$k]['url'] = 'https://' . $url.'/' . $v['short_url'] . '.html';
                $list[$k]['url'] = 'https://' . $url.'/' . $v['short_url'] ;
            }
            $list[$k]['download_num'] = Db::table($bale_rate_table)
                ->where("app_id", $v["id"])
                ->where("status", 1)
                ->cache(true,180)
                ->count();
            $list[$k]["name"]=htmlspecialchars_decode($v["name"]);
            $list[$k]['rate'] = $this->auth->rate . '元/台';
            $list[$k]['create_time'] = !empty($list[$k]['update_time']) ? $list[$k]['update_time'] : $list[$k]['create_time'];
            if (!empty($proxy["wx1_host"])) {
//                $list[$k]["wx_url"] = 'https://' . $proxy['wx1_host'] . '/' . $v['short_url'] . '.html';
                $list[$k]["wx_url"] = 'https://' . $proxy['wx1_host'] . '/' . $v['short_url'] ;
            } else {
//                $list[$k]["wx_url"] = $wx_url . '/' . $v['short_url'] . '.html';
                $list[$k]["wx_url"] = $wx_url . '/' . $v['short_url'] ;
//                $list[$k]["wx_url"] = $wx_url. '?' . $v['short_url'] . '';
            }
            $list[$k]["icon"] = $public_url . "/" . substr($v["icon"], strpos($v["icon"], 'upload/'));
            $list[$k]["apktotal"] = ProxyAppApkDownloadLog::where("app_id", $v["id"])
                ->where("user_id", $user_id)
                ->cache(true,180)
                ->count();
            $v1_total = Db::table($bale_rate_v1_table)
                ->connect("v1_ios")
                ->where("app_id", $v["id"])
                ->where("status", 1)
                ->cache(true,180)
                ->count();
            $list[$k]["v1_total"] =$v1_total;
        }
        $result = [
            'total' => $total,
            'currentPage' => $page,
            'list' => array_values($list)
        ];
        $this->result('success', $result, 200);
    }

    /**
     * 应用操作(代理)
     * @ApiWeigh  2
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="app_id", type="Int", required=true, description="应用id")
     * @ApiParams   (name="user_id", type="Int", required=true, description="用户id")
     * @ApiParams   (name="type", type="Int", required=true, description="操作类型;1-上架;2-下架;")
     */
    public function appHandle()
    {
        $app_id = $this->request->param('app_id');
        $user_id = $this->request->param('user_id');
        $type = $this->request->param('type');
        if (!$app_id || !$user_id || !$type) {
            $this->error(__('Invalid parameters'));
        }
        $info = ProxyUser::get(['id' => $user_id, 'pid' => $this->auth->id]);
        if (!$info) {
            $this->error('会员不存在');
        }

        $app = ProxyApp::get(['id' => $app_id, 'user_id' => $user_id]);
        if (!$app) {
            $this->error(__('应用不存在'));
        }
        switch ($type) {
            case 1;
               // $this->error(__('该应用已被系统自动下架，如需上架，请联系商务人员'));
                if ($app['status'] == -1) {
                    $this->error(__('该应用已被系统自动下架，如需上架，请联系商务人员'));
                }
                $update['is_stop'] = 0;
                break;
            case 2;
             //   $this->error(__('该应用已被系统自动下架，如需上架，请联系商务人员'));
                if ($app['status'] == -1) {
                    $this->error(__('该应用已被系统自动下架，如需上架，请联系商务人员'));
                }
                $update['is_stop'] = 1;
                break;
            default;
                $this->error(__('Invalid parameters'));
                break;
        }
        try {
            ProxyApp::where(['id' => $app_id, 'user_id' => $user_id])->update($update);
        } catch (\Exception $e) {
            $this->error('操作失败');
        }
        /**
         * @todo APP缓存清理
         */
        Redis::del("app_tag:".$app["tag"],0);
        Redis::del("app_tag:".$app["tag"],4);
        Redis::del("app_short_url:".$app["short_url"],4);
        $this->result('success', [], 200);
    }

    /**
     * 应用上传
     * @ApiWeigh  1
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="display_name", type="String", required=true, description="应用名称")
     * @ApiParams   (name="path", type="String", required=true, description="应用地址")
     * @ApiParams   (name="icon", type="String", required=true, description="应用icon")
     * @ApiParams   (name="ipa_data_bak", type="String", required=true, description="ipa_data_bak")
     * @ApiParams   (name="package_name", type="String", required=true, description="包名")
     * @ApiParams   (name="version_code", type="String", required=true, description="版本号")
     * @ApiParams   (name="bundle_name", type="String", required=true, description="bundle_name")
     * @ApiParams   (name="status", type="Int", required=true, description="应用状态1-正常0-关闭")
     * @ApiParams   (name="filesize", type="Int", required=true, description="大小")
     * @ApiParams   (name="desc", type="String", required=true, description="简介")
     * @ApiParams   (name="score_num", type="Int", required=true, description="评分人数")
     * @ApiParams   (name="introduction", type="Int", required=true, description="功能介绍")
     * @ApiParams   (name="img", type="String", required=false, description="图片")
     * @ApiParams   (name="download_code", type="String", required=false, description="下载码")
     * @ApiParams   (name="apk_url", type="String", required=false, description="安卓下载地址")
     * @ApiParams   (name="download_limit", type="Int", required=false, description="限制下载次数")
     * @ApiParams   (name="remark", type="String", required=false, description="备注")
     * @ApiParams   (name="type", type="Int", required=true, description="应用类型 1-超级签名 2-企业签名")
     */
    public function add()
    {
        set_time_limit(0);
        $post = $this->request->post();
        $ip = $this->request->ip();
        $user = ProxyUser::get(['id' => $this->auth->id, 'status' => 'normal']);
        if (empty($user)) {
            $this->error(__('会员不存在'));
        }
        if ($user["sign_num"] <= 0) {
            $this->error(__('您的次数不足，请先充值'));
        }
        if(!empty($post["kf_url"])){
            if(filter_var($post["kf_url"],FILTER_VALIDATE_URL)===false){
                $this->error("请输入正确的客服链接");
            }
        }

        if(!empty($post["cnzz"])){
            if(!is_numeric($post["cnzz"])){
                $this->error("请输入正确的CNZZ统计ID");
            }
        }
        if(empty($post["package_name"])){
            $this->error("包解析失败，请上传正确IPA包");
        }
        $is_sign = true;
        try {
            /***黑名单**/
            $keywords = Config::where("name", "app_blacklist")->value('value');
            if (!empty($keywords)) {
                $keywordsArr = explode('|', $keywords);
                if (is_array($keywordsArr)) {
                    $keywordsArr = array_filter($keywordsArr);
                    foreach ($keywordsArr as $k => $v) {
                        if (trim($post["display_name"]) == trim($v) || trim($post["bundle_name"]) == trim($v)) {
                            $is_sign = false;
                            $bot = new BotApi("1570861671:AAFoeYznUNYhNGgj5yKFby36SoNAITetkmc");
                            $bot->sendMessage("-667020081", "APP:" . $post['display_name'] . "   用户ID:" . $user["id"] . "  包新增 名称在黑名单,上传IP：$ip ," . $post["display_name"] . "，及时查看");
                            break;
                        }
                        if (!empty(trim($v)) && (strpos($post['display_name'], trim($v)) !== false || strpos($post['bundle_name'], trim($v)) !== false)) {
                            $bot = new BotApi("1570861671:AAFoeYznUNYhNGgj5yKFby36SoNAITetkmc");
                            $bot->sendMessage("-667020081", "APP:" . $post['display_name'] . "   用户ID:" . $user["id"] . "  包新增 名称包含在黑名单,上传IP：$ip ," . $post["display_name"] . "，及时查看");
                        }
                    }
                }
            }

            $is_overseas = $post["is_overseas"] ?? 10;
            $is_update = $post['is_update'] ?? 0;
            $tag = uniqid();
            $is_exit = ProxyApp::where('tag', $tag)->value('id');
            if ($is_exit) {
                $tag = uniqid();
                $is_exit = ProxyApp::where('tag', $tag)->value('id');
                if ($is_exit) {
                    $this->error(__('添加应用失败'));
                }
            }
            if (!empty($user["account_id"])) {
                $account = Enterprise::where("id", $user["account_id"])->find();
                if (empty($account)) {
                    $account = Enterprise::where("status", 1)->find();
                }
            } else {
                $account = Enterprise::where("status", 1)->find();
            }
            if (empty($account)) {
                $this->error("证书不足，暂时无法签名");
            }
            $id = (new ProxyApp())->count('id');
            $length = strlen($id);
            if ($length > 2) {
                $short_length = ($length - 2) + 2;
            } else {
                $short_length = 3;
            }
            $short_url = Random::alnum($short_length);
            $is_short_url = ProxyApp::where('short_url', $short_url)->value('id');
            if ($is_short_url) {
                $short_url = Random::alnum($short_length);
                $is_short_url = ProxyApp::where('short_url', $short_url)->value('id');
                if ($is_short_url) {
                    $this->error(__('添加应用失败 short'));
                }
            }
            $oss_path = 'app/' . date('Ymd') . '/' . $tag . '.ipa';
            if ($is_overseas == 10) {
                $oss_config = OssConfig::where("status", 1)
                    ->where("name", "oss")
                    ->find();
                $oss = new Oss($oss_config);
            } else {
                $oss = new GoogleOss(true);
            }
            //$oss_read_config = OssConfig::where("status", 1)
                //->where("name", "g_oss_read")
                //->find();
            //$img_oss = new Oss($oss_read_config);
            $google_oss_new = new GoogleOss(true);
            if (isset($post['img']) && !empty($post["img"])) {
                $imgs = [];
                foreach ($post['img'] as $k => $v) {
                    if (strstr($v, 'http')) {
                        $imgs[] = $v;
                    } else {
                        $info = pathinfo($v);
                        $cahe_img = ROOT_PATH . '/runtime/upload/' . date('Ymd') . "/" . time() . rand(100, 999) . "." . $info['extension'];
                        if ($oss->ossDownload($v, $cahe_img)) {
                            $save_img = "upload/" . date("Ymd") . "/" . md5_file($cahe_img) . "." . $info["extension"];
                             if ($google_oss_new->ossUpload($cahe_img, $save_img)) {
//                              $google_oss_new->ossUpload($cahe_img,$save_img);
                                $img_async=curl_client('post',['path'=>$save_img],'http://34.135.101.133:85/index/img_async');
                                $img_async['code']=$img_async['code']??0;
                                //$img_oss->ossUpload($cahe_img, $save_img);
                                if($img_async&&$img_async['code']==200){
                                    $imgs[] = $oss->oss_url() . $save_img;
                                }else{
                                    $this->error("上传应用截图失败，请重新保存");
                                }
                                //$imgs[] = $oss->oss_url() . $save_img;
                             }
                        }
                    }
                }
                $imgs = implode(',', $imgs);
            } else {
                $imgs = '';
            }
            $icon = "";
            $info = pathinfo($post['icon']);
            if ($oss->isExitFile($post['icon'])) {
                $cahe_img = ROOT_PATH . '/runtime/upload/' . date('Ymd') . "/" . time() . rand(100, 999) . "." . $info['extension'];
                if ($oss->ossDownload($post['icon'], $cahe_img)) {
                    $save_img = "upload/" . date("Ymd") . "/" . md5_file($cahe_img) . "." . $info["extension"];
                     if ($google_oss_new->ossUpload($cahe_img, $save_img)) {
                        //$google_oss_new->ossUpload($cahe_img,$save_img);
                        $img_async=curl_client('post',['path'=>$save_img],'http://34.135.101.133:85/index/img_async');
                        $img_async['code']=$img_async['code']??0;
                        //$img_oss->ossUpload($cahe_img, $save_img);
                        if($img_async&&$img_async['code']==200){
                            $icon = $oss->oss_url() . $save_img;
                        }else{
                            $this->error("上传应用截图失败，请重新保存");
                        }
                        //$icon = $oss->oss_url() . $save_img;
                     }
                }
            } else {
                $this->error("未读取到应用ICON，请手动上传ICON");
            }
            if (empty($icon)) {
                $this->error("上传ICON失败，请重新保存");
            }
            /**下载背景图**/
            $download_bg = "";
            if (!empty($post["download_bg"])) {
                $info_download_pg = pathinfo($post['download_bg']);
                $cahe_img = ROOT_PATH . '/runtime/upload/' . date('Ymd') . "/" . time() . rand(100, 999) . "." . $info_download_pg['extension'];
                if ($oss->isExitFile($post['download_bg']) && $oss->ossDownload($post['download_bg'], $cahe_img)) {
                    $save_img = "upload/" . date("Ymd") . "/" . md5_file($cahe_img) . "." . $info_download_pg["extension"];
                    if ($google_oss_new->ossUpload($cahe_img, $save_img)) {
                        //$google_oss_new->ossUpload($cahe_img,$save_img);
                        $img_async=curl_client('post',['path'=>$save_img],'http://34.135.101.133:85/index/img_async');
                        $img_async['code']=$img_async['code']??0;
                        //$img_oss->ossUpload($cahe_img, $save_img);
                        if($img_async&&$img_async['code']==200){
                            $download_bg = $oss->oss_url() . $save_img;
                        }else{
                            $this->error("上传应用截图失败，请重新保存");
                        }
                        //$download_bg = $oss->oss_url() . $save_img;
                    }
                }
            }
            $status = $user["is_check"] == 1 ? 1 : -1;
            $data = [
                'name' => $post['display_name'],
                'type' => $post['type'] ?? 1,
                'path' => $post['path'],
                'tag' => $tag,
                'user_id' => $this->auth->id,
                'icon' => $icon,
                'ipa_data_bak' => urlencode(json_encode($post['ipa_data_bak'])),
                'package_name' => $post['package_name'],
                'version_code' => $post['version_code'],
                'bundle_name' => $post['bundle_name'],
                'status' => $status,//$post['status'],
                'filesize' => $post['filesize'],
                'desc' => $post['desc'],
                'imgs' => $imgs,
                'score_num' => $post['score_num'],
                'introduction' => $post['introduction'],
                'oss_path' => $oss_path,
                'apk_url' => $post['apk_url'] ?? '',
                'create_time' => date('Y-m-d H:i:s'),
                'download_limit' => $post['download_limit'] ?? 0,
                'remark' => $post['remark'] ?? '',
                'is_update' => 0,
                'is_vaptcha' => $post['is_vaptcha'] ?? 0,
                'short_url' => $short_url,
                'is_st' => $post["is_st"] ?? 0,
                'lang' => $post["lang"] ?? "zh",
                'comment' => $post["comment"] ?? "",
                'comment_name' => $post["comment_name"] ?? "",
                'download_bg' => $download_bg,
                'kf_url' => $post["kf_url"] ?? "",
                'cnzz' => $post["cnzz"] ?? "",
                'mode' => 2,
                'is_stop' => 1,
//            'is_resign'=>1
            ];
            if ($user["pid"] == 58) {
                $data["is_tip"] = 0;
            }
            if ($is_sign) {
                $ansyc_data = [
                    'path' => $post['path'],
                    'oss_path' => $oss_path,
                    'cert_path' => $account["oss_path"],
                    'provisioning_path' => $account["oss_provisioning"],
                    'password' => $account["password"],
                    'account_id' => $account["id"],
//                'app_id' => $app['id'],
                    'package_name' => $post['package_name'],
                    'is_resign' => 0,
                    "tag" => $tag,
                    "user_id" => $user["id"],
//                        "oss_id" => $proxy["oss_id"],
//                        "async_oss_config" => $async_oss_config
                ];
                $update_sign_data = [
                    "ansyc_data" => $ansyc_data,
                    "is_overseas" => $is_overseas
                ];
                $data["is_add"] = 1;
                $data["add_data"] = json_encode($update_sign_data);
            }
            $app = ProxyApp::create($data);
            if ($app) {
                $app_scale = Config::where("name", "app_scale")->value("value");

                if ($user["pid"] == 1793) {
                    $app_scale = 35;
                }
                if ($user["pid"] == 2352) {
                    $app_scale = 10;
                }
                if (!empty($app_scale) || $app_scale != 0) {
                    $app_scale_data = [
                        "app_id" => $app["id"],
                        "status" => 1,
                        "scale" => $app_scale,
                        "create_time" => date("Y-m-d H:i:s")
                    ];
                    AutoAppRefush::create($app_scale_data);
                }
                if ($is_sign) {
//                    /**OSS分流***/
                    $proxy = ProxyUser::where(['id' => $user['pid']])->find();
                    /**OSS分流***/
                    $proxyUserDomain = ProxyUserDomain::where(['user_id' => $user['pid']])->find();
//                    if ($proxy["oss_id"]) {
//                        $async_oss_config = OssConfig::where("id", $proxy["oss_id"])
//                            ->find();
//                    } else {
//                        $async_oss_config = null;
//                    }

//                    $ansyc_data = [
//                        'path' => $post['path'],
//                        'oss_path' => $oss_path,
//                        'cert_path' => $account["oss_path"],
//                        'provisioning_path' => $account["oss_provisioning"],
//                        'password' => $account["password"],
//                        'account_id' => $account["id"],
//                        'app_id' => $app['id'],
//                        'package_name' => $post['package_name'],
//                        'is_resign' => 0,
//                        "tag" => $tag,
//                        "user_id" => $user["id"],
////                        "oss_id" => $proxy["oss_id"],
////                        "async_oss_config" => $async_oss_config
//                    ];
//                    $update_sign_data = [
//                        "ansyc_data"=>$ansyc_data,
//                        "is_overseas"=>$is_overseas
//                    ];
//                    $update=[
//                        "id"=>$app["id"],
//                        "is_add"=>1,
//                        "add_data"=>json_encode($update_sign_data),
//                    ];
//                    ProxyApp::update($update);
                    if(!empty($proxyUserDomain["chat_id"])){
                        $chat_id = $proxyUserDomain["chat_id"];
                    }else{
                        $chat_id = "-667020081";
                    }
                    $txt = "APP:< " . $app["name"] . " > ,APP_ID: < " . $app["id"] . " > ,代理： <" . $proxy["username"] . "> ,用户：<" . $user["username"] . ">有新增APP，请及时审核。";
                    $bot = new BotApi("1570861671:AAFoeYznUNYhNGgj5yKFby36SoNAITetkmc");
                    $bot->sendMessage($chat_id, $txt);
                }
            } else {
                $this->error(__('添加应用失败4'));
            }
        }catch (Exception $exception){
            Log::error($exception->getMessage());
            $this->error(__('添加应用失败,请重新提交'));
        }
        $this->result(__('添加应用成功'), ['app_id' => $app['id']], 200);
    }

    /**
     * 应用更新
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=true, description="应用id")
     * @ApiParams   (name="status", type="Int", required=true, description="是否启用;1-启用;0-禁用")
     * @ApiParams   (name="desc", type="String", required=false, description="描述")
     * @ApiParams   (name="img", type="String", required=false, description="应用截图,英文逗号分割")
     * @ApiParams   (name="score_num", type="Int", required=true, description="评分人数")
     * @ApiParams   (name="introduction", type="Int", required=false, description="功能简介")
     * @ApiParams   (name="download_code", type="String", required=false, description="下载码")
     * @ApiParams   (name="apk_url", type="String", required=false, description="安卓下载地址")
     * @ApiParams   (name="download_limit", type="Int", required=false, description="限制下载次数")
     * @ApiParams   (name="path", type="String", required=false, description="应用地址")
     * @ApiParams   (name="remark", type="String", required=false, description="备注")
     */
    public function update()
    {
        set_time_limit(0);
        $post = array_filter($this->request->post());
        if (empty($post['id']) || empty($post['status'])) {
            $this->error('更新应用失败');
        }
        if(!empty($post["kf_url"])){
            if(filter_var($post["kf_url"],FILTER_VALIDATE_URL)===false){
                $this->error("请输入正确的客服链接");
            }
        }
        if(!empty($post["cnzz"])){
            if(!is_numeric($post["cnzz"])){
                $this->error("请输入正确的CNZZ统计ID");
            }
        }
        $ip = $this->request->ip();
        $ip2 = new Ip2Region();
        $ip_address = $ip2->binarySearch($ip);
        $address = explode('|', $ip_address['region']);
        if ($address[0] == "中国" && !in_array($address[2], ["澳门", '香港', "台湾省","台湾"])) {
            $is_overseas = 10;
        } else {
            $is_overseas = 20;
        }
        //看是否需要增加对会员状态进行验证
        $user = ProxyUser::get(['id' => $this->auth->id, 'status' => 'normal']);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }
        if ($user["sign_num"] <= 0) {
            $this->error(__('您的次数不足，请先充值'));
        }

        $app = ProxyApp::get(['id' => $post['id'], 'user_id' => $this->auth->id]);
        if (empty($app)) {
            $this->error('数据错误');
        }
        $proxy_user = ProxyUser::get(['id' => $user['pid']]);
        if ($app["status"] == -1) {
            $this->error(__('该应用已被系统自动下架，如需更新，请联系商务人员'));
        }
        /**不是直接推送 并且packagename不相同**/
        $update = [
            'id' => $post['id'],
            'status' => $post['status'],
            'desc' => $post['desc'] ?? '',
            'score_num' => $post['score_num'] ?? 2000,
            'introduction' => $post['introduction'] ?? '',
            'update_time' => date('Y-m-d H:i:s'),
//            'download_limit' => $post['download_limit'] ?? 0,
            'remark' => $post['remark'] ?? '',
            'is_vaptcha' => $post['is_vaptcha'] ?? 0,
            'is_st' => $post["is_st"] ?? 0,
            'lang' => $post["lang"] ?? "zh",
            'comment' => $post["comment"] ?? "",
            'comment_name' => $post["comment_name"] ?? "",
            'kf_url'=>$post["kf_url"]??"",
            'cnzz'=>$post["cnzz"]??"",
        ];
        if($user["pid"]==58){
            $update["is_tip"]=1;
        }
        if ($is_overseas == 10) {
            $oss_config = OssConfig::where("status",1)
                ->where("name","oss")
                ->find();
            $oss = new Oss($oss_config);
        }else{
            $oss = new GoogleOss(true);
        }
        $oss_read_config = OssConfig::where("status",1)
            ->where("name","g_oss_read")
            ->find();
        //$img_oss = new Oss($oss_read_config);
//        $google_oss = new GoogleOss();
         $google_oss_new = new GoogleOss(true);
        if (isset($post['img'])) {
            $imgs = [];
            foreach ($post['img'] as $k => $v) {
                if (strstr($v, 'http')) {
                    $imgs[] = $oss->oss_url() . "/" . substr($v, strpos($v, 'upload/'));
                } else {
                    $info = pathinfo($v);
                    $cahe_img = ROOT_PATH . '/runtime/upload/' . date('Ymd') . "/" . time() . rand(100, 999) . "." . $info['extension'];
                    if ($oss->isExitFile($v) && $oss->ossDownload($v, $cahe_img)) {
                        $save_img = "upload/" . date("Ymd") . "/" . md5_file($cahe_img) . "." . $info["extension"];
                         if ( $google_oss_new->ossUpload($cahe_img,$save_img)) {
                            //$google_oss_new->ossUpload($cahe_img,$save_img);
                            $img_async=curl_client('post',['path'=>$save_img],'http://34.135.101.133:85/index/img_async');
                            $img_async['code']=$img_async['code']??0;
                            //$img_oss->ossUpload($cahe_img, $save_img);
                            if($img_async&&$img_async['code']==200){
                                $imgs[] = $oss->oss_url() . $save_img;
                            }else{
                                $this->error("上传应用截图失败，请重新保存");
                            }
                            //$imgs[] = $oss->oss_url() . $save_img;
                         }
                    }
                }
            }
            $update['imgs'] = implode(',', $imgs);
        } else {
            $update['imgs'] = '';
        }
        if (!empty($post["icon"]) && !strstr($post["icon"], 'http')) {
            $info = pathinfo($post['icon']);
            $cahe_img = ROOT_PATH . '/runtime/upload/' . date('Ymd') . "/" . time() . rand(100, 999) . "." . $info['extension'];
            if ($oss->isExitFile($post['icon']) && $oss->ossDownload($post['icon'], $cahe_img)) {
                $save_img = "upload/" . date("Ymd") . "/" . md5_file($cahe_img) . "." . $info["extension"];
                 if ( $google_oss_new->ossUpload($cahe_img,$save_img)) {
                    //$google_oss_new->ossUpload($cahe_img,$save_img);
                    $img_async=curl_client('post',['path'=>$save_img],'http://34.135.101.133:85/index/img_async');
                    $img_async['code']=$img_async['code']??0;
                    //$img_oss->ossUpload($cahe_img, $save_img);
                    if($img_async&&$img_async['code']==200){
                        $update["icon"] = $oss->oss_url() . $save_img;
                    }else{
                        $this->error("上传应用截图失败，请重新保存");
                    }
                    //$update["icon"] = $oss->oss_url() . $save_img;
                 }
            }
        }
        /**下载背景图**/
        if (!empty($post["download_bg"])) {
            if(strstr($post["download_bg"],'http')){
                $update["download_bg"] =$post["download_bg"];
            }else{
                $info = pathinfo($post['download_bg']);
                $cahe_img = ROOT_PATH . '/runtime/upload/' . date('Ymd') . "/" . time() . rand(100, 999) . "." . $info['extension'];
                if ($oss->isExitFile($post['download_bg']) && $oss->ossDownload($post['download_bg'], $cahe_img)) {
                    $save_img = "upload/" . date("Ymd") . "/" . md5_file($cahe_img) . "." . $info["extension"];
                     if ( $google_oss_new->ossUpload($cahe_img,$save_img)) {
                        //$google_oss_new->ossUpload($cahe_img,$save_img);
                        $img_async=curl_client('post',['path'=>$save_img],'http://34.135.101.133:85/index/img_async');
                        $img_async['code']=$img_async['code']??0;
                        //$img_oss->ossUpload($cahe_img, $save_img);
                        if($img_async&&$img_async['code']==200){
                            $update["download_bg"] = $oss->oss_url() . $save_img;
                        }else{
                            $this->error("上传应用截图失败，请重新保存");
                        }
                        //$update["download_bg"] = $oss->oss_url() . $save_img;
                     }
                }
            }
        }else{
            $update["download_bg"] ="";
        }
        /***检查是否为更新应用***/
        if (isset($post['path']) && !empty($post['path'])) {
            if(empty($post["package_name"])){
                $this->error("包解析失败，请上传正确IPA包");
            }
            /***更新次数**/
            $updata_log_num = ProxyAppUpdateLog::where("app_id",$app['id'])->count("id");
            $bale_rate_table = getTable("proxy_bale_rate", $user["pid"]);
            $is_sign = true;
            if($post["package_name"]!=$app["package_name"]){
                $error_update=[
                    "name"=>$app["name"],
                    "new_name"=>$post["display_name"],
                    "app_id"=>$app["id"],
                    "user_id"=>$app["user_id"],
                    "create_time"=>date("Y-m-d H:i:s"),
                ];
                ProxyAppUpdateErrorLog::create($error_update);
                $this->error(__('BundleID不一致，无法更新应用'));
            }
            /***黑名单**/
            $keywords = Config::where("name","app_blacklist")->value('value');
            if (!empty($keywords)) {
                $keywordsArr = explode('|', $keywords);
                if (is_array($keywordsArr)) {
                    $keywordsArr = array_filter($keywordsArr);
                    foreach ($keywordsArr as $k => $v) {
                        if(!empty(trim($v))){
                            if(trim($post["display_name"])==trim($v)||trim($post["bundle_name"])==trim($v)){
                                $is_sign=false;
                                $txt = "APP:".$app['name'] ."   ID:".$app["id"]."  名称在黑名单中,上传IP：$ip ,名称： ".$post["display_name"]."; bundle_name:".$post["bundle_name"]."，第".($updata_log_num+1)."次更新;及时查看";
                                $bot = new BotApi("1570861671:AAFoeYznUNYhNGgj5yKFby36SoNAITetkmc");
                                $bot->sendMessage("-667020081",$txt);
                                break;
                            }
                            if (strpos($post['display_name'], trim($v)) !== false||strpos($post['bundle_name'], trim($v)) !== false) {
                                $txt = "APP:".$app['name'] ."   ID:".$app["id"]." 名称中包含黑名单关键字,上传IP：$ip ,名称： ".$post["display_name"]."; bundle_name:".$post["bundle_name"]."，第".($updata_log_num+1)."次更新;及时查看";
                                $bot = new BotApi("1570861671:AAFoeYznUNYhNGgj5yKFby36SoNAITetkmc");
                                $bot->sendMessage("-667020081",$txt);
                            }
                        }
                    }
                }
            }

            /**解除大小限制***/
            $app_no_check_bundname_user = Config::where("name","app_no_check_bundname_user")->value('value');
            if (!empty($app_no_check_bundname_user)) {
                $app_no_check_bundname_user_id = explode(',', $app_no_check_bundname_user);
                if(in_array($app["id"],$app_no_check_bundname_user_id)){
                    $is_check_bundname=false;
                }else{
                    $is_check_bundname=true;
                }
            }else{
                $is_check_bundname=true;
            }

            if( $is_check_bundname && $post["bundle_name"]!=$app["bundle_name"]){
                $is_sign = false;
                $error_update=[
                    "name"=>$app["name"],
                    "new_name"=>$post["display_name"],
                    "app_id"=>$app["id"],
                    "user_id"=>$app["user_id"],
                    "create_time"=>date("Y-m-d H:i:s"),
                ];
                ProxyAppUpdateErrorLog::create($error_update);
               $pay_num = Db::table($bale_rate_table)->where("app_id",$app["id"])
                    ->where('status', 1)
                    ->where('is_auto', 0)
                    ->cache(true, 300)
                    ->count("id");//总下载
                $txt = "APP:".$app['name'] ."   ID:".$app["id"]."  包更新 bundle_name不一致,上传IP：$ip ,原始名称：".$app["bundle_name"]." ; 新名称： ".$post["bundle_name"]."，第".($updata_log_num+1)."次更新;";
                if($pay_num <=20){
                    $txt .="总下载次数： ".$pay_num. " ;";
                }
                $txt .="及时查看";
                $bot = new BotApi("1570861671:AAFoeYznUNYhNGgj5yKFby36SoNAITetkmc");
                $bot->sendMessage("-667020081",$txt);
               // $this->error(__('BundleID不一致，无法更新应用'));
            }

            if($user["is_check_update"]==1){
                if(trim($post["display_name"])!=trim($app["name"])){
                    $pay_num = Db::table($bale_rate_table)->where("app_id",$app["id"])
                        ->where('status', 1)
                        ->where('is_auto', 0)
                        ->cache(true, 300)
                        ->count("id");//总下载
                    $txt = "APP:".$app['name'] ."   ID:".$app["id"]."  包更新 名称不一致,上传IP：$ip ,新名称：".$post["display_name"]." ;第".($updata_log_num+1)."次更新;";
                    if($pay_num <=20){
                        $txt .="总下载次数： ".$pay_num. " ;";
                    }
                    $txt .="及时查看";
                    $bot = new BotApi("1570861671:AAFoeYznUNYhNGgj5yKFby36SoNAITetkmc");
                    $bot->sendMessage("-667020081",$txt);
                }
                $diff = abs($app["filesize"]-$post["filesize"]);
                /**解除大小限制***/
                $app_update_diff_size = Config::where("name","app_update_diff_size")->value('value');
                if (!empty($app_update_diff_size)) {
                    $app_update_diff_size_id = explode(',', $app_update_diff_size);
                    if(in_array($app["id"],$app_update_diff_size_id)){
                        $diff=0;
                    }
                }
                if($diff>(10*1024*1024)){
                    $is_sign = false;
                    $pay_num = Db::table($bale_rate_table)->where("app_id",$app["id"])
                        ->where('status', 1)
                        ->where('is_auto', 0)
                        ->cache(true, 300)
                        ->count("id");//总下载
                    $txt = "APP:".$app['name'] ."   ID:".$app["id"]."  包大小不一致,上传IP：$ip ,原始包大小：".format_bytes($app["filesize"]).",上传包大小：".format_bytes($post["filesize"])." ;第".($updata_log_num+1)."次更新;";
                    if($pay_num <=20){
                        $txt .="总下载次数： ".$pay_num. " ;";
                    }
                    $txt .="及时查看";
                    $bot = new BotApi("1570861671:AAFoeYznUNYhNGgj5yKFby36SoNAITetkmc");
                    $bot->sendMessage("-667020081",$txt);
                }elseif ($diff<=(10*1024*1024) && $diff>(5*1024*1024)){
                    $pay_num = Db::table($bale_rate_table)->where("app_id",$app["id"])
                        ->where('status', 1)
                        ->where('is_auto', 0)
                        ->cache(true, 300)
                        ->count("id");//总下载
                    $txt = "APP:".$app['name'] ."   ID:".$app["id"]."  包大小不一致,上传IP：$ip ,原始包大小：".format_bytes($app["filesize"]).",上传包大小：".format_bytes($post["filesize"])." ;第".($updata_log_num+1)."次更新;";
                    if($pay_num <=20){
                        $txt .="总下载次数： ".$pay_num. " ;";
                    }
                    $txt .="及时查看";
                    $bot = new BotApi("1570861671:AAFoeYznUNYhNGgj5yKFby36SoNAITetkmc");
                    $bot->sendMessage("-667020081",$txt);
                }
            }
            $account = Enterprise::where("id",$app["account_id"])
                ->find();
            if (empty($account)) {
                $account = Enterprise::where("id", $user["account_id"])->find();
                if(empty($account)) {
                    $account = Enterprise::where("status", 1)
                        ->find();
                    if (empty($account)) {
                        $this->error("证书不足，暂时无法签名");
                    }
                }
            }
            $app_data = [
                'name' => $post['display_name'],
                'path' => $post['path'],
                'ipa_data_bak' => urlencode(json_encode($post['ipa_data_bak'])),
//                'package_name' => $post['package_name'],
//                'bundle_name' => $post['bundle_name'],
                'filesize' => $post['filesize'],
                'system_version' => $app['system_version'] + 1,
                'version_code' => $post['version_code'],
                'old_icon' => $app['icon'],
            ];
            /**记录上个版本**/
            $formerly_data = [
                'app_id' => $app['id'],
                'imgs' => $app['imgs'],
                'name' => $app['name'],
                'path' => $app['path'],
                'icon' => $app['icon'],
                'ipa_data_bak' => $app['ipa_data_bak'],
                'package_name' => $app['package_name'],
                'version_code' => $app['version_code'],
                'bundle_name' => $app['bundle_name'],
                'filesize' => $app['filesize'],
                'system_version' => $app['system_version'],
                'create_time' => date('Y-m-d H:i:s')
            ];
            $update = array_merge($update, $app_data);

            /**OSS分流***/
            $proxy = ProxyUserDomain::where(['user_id' => $user['pid']])->find();
            if($proxy["oss_id"]){
                $async_oss_config = OssConfig::where("id",$proxy["oss_id"])
                    ->find();
            }else{
                $async_oss_config = null;
            }
            if($is_sign) {
                $oss_path = 'app/' . date('Ymd') . '/' . $app["tag"].date("Hi") . '.ipa';
                $ansyc_data = [
                    'path' => $post['path'],
                    'oss_path' => $oss_path,
                    'cert_path' => $account["oss_path"],
                    'provisioning_path' => $account["oss_provisioning"],
                    'password' => $account["password"],
                    'account_id' => $account["id"],
                    'app_id' => $app['id'],
                    'package_name' => $post['package_name'],
                    'is_resign' => $app["is_resign"],
                    "tag" => $app["tag"],
                    "oss_id" => $proxy["oss_id"],
                    "user_id" => $user["id"],
                    "async_oss_config" => $async_oss_config,
                    "bundle_name"=>$post["bundle_name"],
                    'name' => $post['display_name'],
                    'is_update' => 1,
                ];

                $update_sign_data = [
                    "bundle_name"=>$post["bundle_name"],
                    "ansyc_data"=>$ansyc_data,
                    "is_overseas"=>$is_overseas
                ];
                $update["update_data"]=json_encode($update_sign_data);
                $update["is_update"]=1;
                $pay_num = Db::table($bale_rate_table)->where("app_id",$app["id"])
                    ->where('status', 1)
                    ->where('is_auto', 0)
                    ->cache(true, 300)
                    ->count("id");//总下载
                $txt = "APP:< ".$app["name"]." > ,APP_ID: < ".$app["id"]." > ,代理： <".$proxy_user["username"]."> ,用户：<".$user["username"].">有更新，请及时审核。"."  第".($updata_log_num+1)."次更新;";
                if($pay_num <=20){
                    $txt .="总下载次数： ".$pay_num. " ;";
                }
                if(!empty($proxy["chat_id"])){
                    $chat_id = $proxy["chat_id"];
                }else{
                    $chat_id = "-667020081";
                }
                $bot = new BotApi("1570861671:AAFoeYznUNYhNGgj5yKFby36SoNAITetkmc");
                $bot->sendMessage($chat_id, $txt);
            }
        }
        if (ProxyApp::update($update)) {
            if (isset($formerly_data) && !empty($formerly_data)) {
                ProxyAppUpdateLog::create($formerly_data);
            }
            /**
             * @todo APP缓存清理
             */
            Redis::del("app_tag:".$app["tag"],0);
            Redis::del("app_tag:".$app["tag"],4);
            Redis::del("app_short_url:".$app["short_url"],4);
            $this->result('更新成功', [], 200);
        } else {
            $this->error('更新失败');
        }
    }

    /**
     * 应用简介
     * @ApiWeigh  2
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=true, description="应用id")
     * @ApiReturnParams (name="path", type="String", required=true, description="安装包地址")
     * @ApiReturnParams (name="package_name", type="String", required=true, description="包名")
     * @ApiReturnParams (name="introduction", type="String", required=true, description="功能介绍")
     * @ApiReturnParams (name="status", type="Int", required=true, description="状态：1正常0下架")
     * @return array
     */
    public function appDes()
    {
        $id = $this->request->param('id', 0);
        $ip = $this->request->ip();
        if (!$id) {
            $this->error(__('Invalid parameters'));
        }
        $user = ProxyUser::get(['id' => $this->auth->id, 'status' => 'normal']);
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }
        $ip2 = new Ip2Region();
        $ip_address = $ip2->binarySearch($ip);
        $address = explode('|', $ip_address['region']);
        if ($address[0] == "中国" && !in_array($address[2], ["澳门", '香港', "台湾省","台湾"])) {
            $public_url = Config::where('name', 'proxy_zh_oss_public_url')->value('value');
//            $oss_config = OssConfig::where("status", 1)
//                ->where("name", "oss")
//                ->find();
        } else {
            $public_url = Config::where('name', 'proxy_en_oss_public_url')->value('value');
//            $oss_config = OssConfig::where("status", 1)
//                ->where("name", "g_oss")
//                ->find();
        }
//        $oss = new Oss($oss_config);
        $info = ProxyApp::where(['id' => $id, 'user_id' => $this->auth->id])->find();
        if(empty($info)){
            $this->error(__('应用不存在'));
        }
//        if ($info && $info['imgs']) {
//            $info['imgs'] = explode(',', $info['imgs']);
//        }
        if (!empty($info["imgs"])) {
            $cache_imgs = array_filter(explode(',', $info['imgs']));
            foreach ($cache_imgs as $k => $v) {
                $cache_imgs[$k] = $public_url . "/" . substr($v, strpos($v, 'upload/'));
            }
        } else {
            $cache_imgs = [];
        }
        $info['name'] = htmlspecialchars_decode($info["name"]);
        $info['imgs'] = $cache_imgs;
        $info["icon"] = $public_url . "/" . substr($info["icon"], strpos($info["icon"], 'upload/'));
        $info["download_bg"] = empty($info["download_bg"])?null:$public_url . "/" . substr($info["download_bg"], strpos($info["download_bg"], 'upload/'));
        $proxy = ProxyUserDomain::get(['user_id' => $user['pid']]);
        /***获取配置URL**/
        $host = $proxy["download_url"];
        $port_data = DownloadUrl::where("name",$host)
            ->where("status",1)
            ->cache(true,180)
            ->find();
        if(empty($port_data)){
            $port_data = DownloadUrl::where("status",1)
                ->where("is_default")
                ->cache(true,180)
                ->find();
        }
        /**带端口**/
        if(!empty($port_data["web_port"])){
            $ports = explode(",",$port_data["web_port"]);
            $port = $ports[array_rand($ports)];
            $url ="$host:$port";
        }else{
            $url = $host;
        }
//        $ports = [7635,7277];
//        $url = $proxy["download_url"];
//        /**阿里云全站加速**/
//        if(in_array($url,["591lxss.cn",'rlegdvc.cn'])){
//
//        }else{
//            $port = $ports[array_rand($ports)];
//            $url ="$url:$port";
//        }
        if ($proxy["ext"] == "app") {
            $app_apk_url = 'https://' . $url. '/' . $info['tag'] . '.app';
        } else {
//            $app_apk_url = 'https://' . $url.'/' . $info['short_url'] . '.html';
            $app_apk_url = 'https://' . $url.'/' . $info['short_url'] ;
        }
        if (!empty($info["apk_url"]) && strstr("http", $info["apk_url"])) {
            $info["apk_url"] = $app_apk_url;
        }
        /**客服处理**/
        if(!empty($info["kf_url"])){
            $info["kf_url"] = htmlspecialchars_decode($info["kf_url"]);
        }
//        $bale_rate_table = getTable("proxy_bale_rate", $user["pid"]);
//        $list['download_num'] = Db::table($bale_rate_table)->where("app_id", $info["id"])->where("status", 1)->count();
        $info['download_url'] = $app_apk_url;
        $info['path'] = "";
        $info['oss_path'] = "";

        $this->result('success', $info, 200);
    }

    /**
     * 应用版本记录
     * @ApiWeigh  3
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=true, description="应用id")
     * @ApiParams   (name="page", type="Int", required=false, description="当前页")
     * @ApiParams   (name="page_size", type="Int", required=false, description="每页显示条数")
     * @ApiReturnParams (name="version_code", type="Int", required=true, description="系统版本")
     * @ApiReturnParams (name="system_version", type="Int", required=true, description="内部版本")
     */
    public function appUpdateLog()
    {
        $id = $this->request->param('id', 0);
        if (!$id) {
            $this->error(__('Invalid parameters'));
        }
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }

        $app = ProxyApp::get(['id' => $id, 'user_id' => $this->auth->id]);
        if (empty($app)) {
            $this->error('数据错误');
        }

        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 10);
        if (intval($page) < 1) $page = 1;
        $offset = ($page - 1) * $pageSize;
        $where = ['app_id' => $id];
        $total = ProxyAppUpdateLog::where($where)
//            ->cache(true, 1800)
            ->count('id');
        $list = ProxyAppUpdateLog::where($where)
            ->order('system_version', 'desc')
            ->limit($offset, $pageSize)
//            ->cache(true,1800)
            ->column('id,app_id,name,version_code,system_version,path,create_time,filesize');
        foreach ($list as $k => $v) {
            $list[$k]["path"] = "";
            $list[$k]["size"] = format_bytes($v["filesize"]);
        }
        $result = [
            'total' => $total,
            'currentPage' => $page,
            'list' => array_values($list)
        ];
        $this->result('success', $result, 200);
    }

    /**
     * 应用下载记录
     * @ApiWeigh  3
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=true, description="应用id")
     * @ApiParams   (name="page", type="Int", required=false, description="当前页")
     * @ApiParams   (name="page_size", type="Int", required=false, description="每页显示条数")
     * @ApiReturnParams (name="app", type="String", required=true, description="应用名")
     * @ApiReturnParams (name="udid", type="String", required=true, description="设备号")
     */
    public function downloadRecord()
    {
        $id = $this->request->param('id', 0);
        if (!$id) {
            $this->error(__('Invalid parameters'));
        }
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }

        $tag = ProxyApp::where(['id' => $id, 'user_id' => $this->auth->id])->value('tag');
        if (!$tag) {
            $this->error(__('应用不存在'));
        }
        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 10);
        if (intval($page) < 1) $page = 1;
        $offset = ($page - 1) * $pageSize;
        $where = [
            'user_id' => $this->auth->id,
            'tag' => $tag,
        ];
        $total = ProxyDownload::where($where)->cache(true, 1800)->count('id');
        $list = ProxyDownload::where($where)
            ->order('create_time', 'desc')
            ->limit($offset, $pageSize)
            ->cache(true, 1800)
            ->column('id,app,tag,udid,create_time,update_time');
        foreach ($list as &$v) {
            $v['update_time'] = !empty($v['update_time']) ? $v['update_time'] : $v['create_time'];
        }
        $result = [
            'total' => $total,
            'currentPage' => $page,
            'list' => array_values($list)
        ];
        $this->result('success', $result, 200);
    }

    /**
     * 应用消费记录
     * @ApiWeigh  4
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=false, description="应用id")
     * @ApiParams   (name="name", type="String", required=false, description="应用名称")
     * @ApiParams   (name="start", type="String", required=false, description="服务开始时间 Y-m-d")
     * @ApiParams   (name="end", type="String", required=false, description="服务结束时间")
     * @ApiParams   (name="page", type="Int", required=false, description="当前页")
     * @ApiParams   (name="page_size", type="Int", required=false, description="每页显示条数")
     *
     * @ApiReturnParams (name="id", type="string", required=true, description="消费订单编号")
     * @ApiReturnParams (name="name", type="string", required=true, description="应用名称")
     * @ApiReturnParams (name="type", type="integer", required=true, description="应用类型 1-超级签名 2-企业签名")
     * @ApiReturnParams (name="status", type="integer", required=true, description="应用状态 1-进行中 2-已结束")
     */
    public function appPayRecord()
    {
        $id = $this->request->post('id', 0);
        $status = $this->request->post('status', 1);
        $name = $this->request->post('name', null);
        $start = $this->request->post('start', null);
        $end = $this->request->post('end', null);
        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 10);
        if (intval($page) < 1) $page = 1;
        $offset = ($page - 1) * $pageSize;
        $where['b.user_id'] = $this->auth->id;
        $id && $where['b.app_id'] = $id;
        $name && $where['a.name'] = ['like', '%' . $name . '%'];
        //  if($status == 1)
        //  {
        $where['b.status'] = 1;
        //   }
        $end && $end = $end . ' 23:59:59';
        $start && $end && $where['b.create_time'] = ['between', [$start, $end]];
        $user = ProxyUser::where("id", $this->auth->id)->find();
        $bale_rate_table = getTable("proxy_bale_rate", $user["pid"]);
        $proxy_views_table = getTable("proxy_app_views", $user["pid"], 100);
        $total = Db::table($bale_rate_table)
            ->alias('b')
            ->join('proxy_app a', 'b.app_id = a.id')
            ->where($where)
//            ->cache(true,1800)
            ->count();
        $list = Db::table($bale_rate_table)->alias('b')
            ->join('proxy_app a', 'b.app_id = a.id')
            ->where($where)
            ->field('a.id as app_id,a.name,b.udid,a.type,b.create_time,b.status,b.ip,b.resign_udid,b.device')
            ->order('b.create_time', 'desc')
            ->limit($offset, $pageSize)
//            ->cache(true,1800)
            ->select();
        foreach ($list as $k => $v) {
            if ($v["resign_udid"]) {
                $list[$k]["udid"] = $v["resign_udid"];
            }
            $list[$k]["money"] = 1;
            if (empty($v['ip'])) {
                $v['ip'] = Db::table($proxy_views_table)->where(['app_id' => $v['app_id'], 'udid' => $v['udid']])->cache(true, 1800)->value('ip');
            }
        }
        $result = [
            'total' => $total,
            'currentPage' => $page,
            'list' => array_values($list)
        ];
        $this->result('success', $result, 200);
    }

    /**
     * 重签收费记录
     * @ApiWeigh  4
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=false, description="应用id")
     * @ApiParams   (name="name", type="String", required=false, description="应用名称")
     * @ApiParams   (name="start", type="String", required=false, description="服务开始时间 Y-m-d")
     * @ApiParams   (name="end", type="String", required=false, description="服务结束时间")
     * @ApiParams   (name="page", type="Int", required=false, description="当前页")
     * @ApiParams   (name="page_size", type="Int", required=false, description="每页显示条数")
     *
     */
    protected function resign_free()
    {
        $id = $this->request->post('id', 0);
        $name = $this->request->post('name');
        $start = $this->request->post('start');
        $end = $this->request->post('end');
        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 10);
        if (intval($page) < 1) $page = 1;
        $offset = ($page - 1) * $pageSize;

        //$user = ProxyUser::get(['id'=>$this->auth->id,'status'=>'normal']);
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }
        //需要检查用户对这个app是否具有管理权限，优先从小表进行排除操作，多一步操作可能会减少对大表的操作
        $app = ProxyApp::get(['id' => $id, 'user_id' => $this->auth->id]);
        if (empty($app)) {
            $this->error('数据错误');
        }

        $where['b.user_id'] = $this->auth->id;
        $id && $where['b.app_id'] = $id;
        $name && $where['a.name'] = ['like', '%' . $name . '%'];
        $start && $end && $where['b.create_time'] = ['between', [$start, $end]];
        $model = new ProxyResignFree();
        $total = $model
            ->alias('b')
            ->join('proxy_app a', 'b.app_id = a.id')
            ->where($where)
            ->cache(true, 1800)
            ->count();
        $list = $model->alias('b')
            ->join('proxy_app a', 'b.app_id = a.id')
            ->where($where)
            ->field('a.id as app_id,a.name,b.rate as money,b.udid,a.type,b.create_time')
            ->order('b.create_time', 'desc')
            ->limit($offset, $pageSize)
            ->cache(true, 1800)
            ->select();
        foreach ($list as &$v) {
            $v['status'] = 1;
        }
        $result = [
            'total' => $total,
            'currentPage' => $page,
            'list' => array_values($list)
        ];
        $this->result('success', $result, 200);
    }

    /**
     * 应用统计
     * @ApiWeigh  4
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=false, description="应用id")
     * @ApiReturnParams (name="mtotal", type="integer", required=true, description="总消费")
     * @ApiReturnParams (name="mtoday", type="integer", required=true, description="今日新增消费")
     * @ApiReturnParams (name="vtotal", type="integer", required=true, description="总浏览量")
     * @ApiReturnParams (name="vtoday", type="integer", required=true, description="今日新增浏览量")
     * @ApiReturnParams (name="dtotal", type="integer", required=true, description="总下载")
     * @ApiReturnParams (name="dtoday", type="integer", required=true, description="今日新增下载")
     * @ApiReturnParams (name="utotal", type="integer", required=true, description="总设备")
     * @ApiReturnParams (name="utoday", type="integer", required=true, description="今日新增设备")
     * @ApiReturnParams (name="rtotal", type="integer", required=true, description="补签")
     * @ApiReturnParams (name="rtoday", type="integer", required=true, description="今日新增补签")
     */
    public function appStatistics()
    {
        $id = $this->request->param('id', 0);
        $user = ProxyUser::get(['id' => $this->auth->id, 'status' => 'normal']);
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->result('success', [], 200);
        }
        $bale_rate_table = getTable("proxy_bale_rate", $user["pid"]);
        $download_table = getTable("proxy_download", $user["pid"]);
        $views_table = getTable("proxy_app_views", $user["pid"], 100);

        if ($id)//指定设备
        {
            //需要检查用户对这个app是否具有管理权限，优先从小表进行排除操作，多一步操作可能会减少对大表的操作
            $app = ProxyApp::get(['id' => $id, 'user_id' => $this->auth->id]);
            if (empty($app)) {
                $this->result('success', [], 200);
            }

            $where = ['app_id' => $id];
            $download_where = ['tag' => $app['tag'], 'user_id' => $this->auth->id];
        } else {
            $where = [
                'user_id' => $this->auth->id,
            ];
            $download_where = ['user_id' => $this->auth->id];
        }
        $list = [];
//        $list['mtotal'] = Db::table($bale_rate_table)->where($where)
//            ->where('status', 1)
//            ->cache(true, 300)
//            ->count("id");//总次数
//        $list['mtoday'] = Db::table($bale_rate_table)->where($where)
//            ->where('status', 1)
//            ->whereTime('create_time', 'today')
//            ->cache(true, 300)
//            ->count("id");//今日新增消费次数
//        $list['vtotal'] = Db::table($views_table)->where($where)
//            ->cache(true, 300)
//            ->count("id");//总浏览量
        $list['vtoday']= $list['vtotal'] =0;
//        $list['vtoday']= $list['vtotal'] = Db::table($views_table)->where($where)
//            ->whereTime('create_time', 'today')
//            ->cache(true, 300)
//            ->count("id");//今日新增浏览量
        $list['dtotal'] = Db::table($bale_rate_table)->where($where)
            ->where('status', 1)
            ->cache(true, 300)
            ->count("id");//总下载
        $list['dtoday'] = Db::table($bale_rate_table)->where($where)
            ->where('status', 1)
            ->whereTime('create_time', 'today')
//            ->cache(true, 300)
            ->count("id");//今日新增下载
//
//        $list['utotal'] = Db::table($bale_rate_table)->where($where)
//            ->where('status', 1)
////            ->group("udid")
//            ->cache(true, 300)
//            ->count("id");//总下载
//        $list['utoday'] = Db::table($bale_rate_table)->where($where)
//            ->where('status', 1)
//            ->whereTime('create_time', 'today')
////            ->group("udid")
//            ->cache(true, 300)
//            ->count("id");//今日新增下载
//
//        $list['utotal'] = Db::table($download_table)->where($download_where)
//            ->cache(true, 1800)
//            ->count('distinct id');//总设备 去重
//        $list['utoday'] = Db::table($download_table)->where($download_where)
//            ->whereTime('create_time', 'today')
//            ->cache(true, 1800)
//            ->count('id');//今日新增设备
        $list['utotal'] = $list['mtotal'] = $list['dtotal'];
        $list['utoday'] = $list['mtoday'] = $list['dtoday'];
        $list["apk_today_num"] = ProxyAppApkDownloadLog::where($where)
            ->whereTime('create_time', 'today')
//            ->cache(true, 300)
            ->count('id'); //今日新增设备
        $list["apk_total_num"] = ProxyAppApkDownloadLog::where($where)
//            ->cache(true, 300)
            ->count('id');//今日新增设备
        $list["sign_num"] = intval($user["sign_num"]);
        $this->result('success', $list, 200);
    }

    /**
     * 应用详情
     * @ApiWeigh  2
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=true, description="应用id")
     * @ApiParams   (name="start", type="string", required=false, description="开始时间默认当月开始")
     * @ApiParams   (name="end", type="string", required=false, description="结束时间默认当天结束")
     * @ApiReturnParams (name="money", type="integer", required=true, description="总消费")
     * @ApiReturnParams (name="views", type="integer", required=true, description="总浏览")
     * @ApiReturnParams (name="download", type="integer", required=true, description="总下载")
     * @ApiReturnParams (name="new", type="integer", required=true, description="新增")
     * @ApiReturnParams (name="equipment", type="integer", required=true, description="设备")
     * @return array
     */
    public function appInfo()
    {
        $beginThismonth = mktime(0, 0, 0, date('m'), 1, date('Y'));
        $endThisday = strtotime(date("Y-m-d", time()));
        $id = $this->request->param('id', 0);
        $start = $this->request->param('start', $beginThismonth);
        $end = $this->request->param('end', $endThisday);
        $end = $end . ' 23:59:59';

        $user = ProxyUser::get(['id' => $this->auth->id, 'status' => 'normal']);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            //$this->error(__('会员不存在'));
            $this->result('success', [], 200);
        }

        $bale_rate_table = getTable("proxy_bale_rate", $user["pid"]);
        $download_table = getTable("proxy_download", $user["pid"]);
        $views_table = getTable("proxy_app_views", $user["pid"], 100);

        $appList = ProxyApp::where(['user_id' => $this->auth->id])->select();
        if (empty($appList)) {
            $this->result('success', [], 200);
        }

        $where['user_id'] = $this->auth->id;
        $id && $where['app_id'] = $id;
        if ((strtotime($end) - strtotime($start)) > (24 * 60 * 60)) {
            $list_time = range(strtotime($start), strtotime($end), 24 * 60 * 60);
            $list_time = array_map(function ($val) {
                return date('Y-m-d', $val);
            }, $list_time);
        } else {
            $list_time = [
                date('Y-m-d', strtotime($start))
            ];
        }

        $list = [];
        foreach ($list_time as $k => $v) {
            $time = strtotime($v." 00:00:00");
            if($time>time()){
                $pay_day_num = 0;
                $apk_day_num = 0;
            }else{
                $pay_day_num = Db::table($bale_rate_table)->where($where)
                    ->where(['status' => 1])
                    ->whereTime("create_time","between",[$v." 00:00:00",$v." 23:59:59"])
                    ->cache(true, 1800)
                    ->count("id");
                $apk_day_num =  ProxyAppApkDownloadLog::where($where)
                    ->whereTime("create_time","between",[$v." 00:00:00",$v." 23:59:59"])
                    ->cache(true, 1800)
                    ->count("id");
            }
            $list["money"][$k] = $pay_day_num;
            $list["download"][$k] = $pay_day_num;
            $list["new"][$k] = $pay_day_num;
            $list["equipment"][$k] = $pay_day_num;
            $list["apk"][$k] = $apk_day_num;
            $list["dates_whole"][$k]= date("Y-m-d",$time);
            $list["dates"][$k]= date("m-d",$time);
        }
        $list['total'] = [
            'money' => array_sum($list['money']),
//            'views' => array_sum($list['views']),
            'download' => array_sum($list['download']),
            'new' => array_sum($list['new']),
            'equipment' => array_sum($list['equipment']),
            'apk' => array_sum($list['apk']),
        ];

        $this->result('success', $list, 200);
    }

    /**
     * 区域下载量
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=false, description="应用id")
     * @ApiParams   (name="start", type="String", required=false, description="开始时间")
     * @ApiParams   (name="end", type="String", required=false, description="结束时间")
     */
    public function downloadArea()
    {
        $id = $this->request->post('id', null);
        $start = $this->request->post('start', null);
        $end = $this->request->post('end', null);

        //$user = ProxyUser::get(['id'=>$this->auth->id,'status'=>'normal']);
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            //$this->error(__('会员不存在'));
            $this->result('success', [], 200);
        }
        if (!empty($id)) {
            $info = ProxyApp::get(['id' => $id, 'user_id' => $this->auth->id]);
            if (!$info) {
                $this->result('success', [], 200);
            }
        }
        $where['user_id'] = $this->auth->id;
        if(empty($id)){
            $key = "app_area_user:".$this->auth->id.":";
        }else{
            $key = "app_area:".$id.":";
        }
        $id && $where['app_id'] = $id;
        if($start && $end){
            $key .=$start."_".$end;
            $end = $end . ' 23:59:59';
            $where['create_time'] = ['between', [$start, $end]];
        }else{
            $time = date("Y-m-d");
            $where['create_time'] = ['between', [$time, $time." 23:59:59"]];
            $key .=$time;
        }
        $views_table = getTable("proxy_app_views", $user["pid"], 100);
        $redis = new Redis(['select' => 13]);
        $rand = $redis->handle()->zRevRange($key,0,100,true);
        $redis->handle()->close();
        if(empty($rand)){
            $offset = 0;
            $num = 0;
            $redis = new Redis(['select' => 13]);
            while (true){
                $num++;
                $cache = Db::table($views_table)->where($where)
                    ->order("create_time","DESC")
                    ->cache(true, 1800)
                    ->limit($offset,1000)
                    ->column("id,ip,app_id");
                $offset +=1000;
                foreach ($cache as $v){
                    $redis->handle()->zIncrBy($key,1,$v["ip"]);
                }
                if(count($cache)<800){
                    $redis->handle()->close();
                    break;
                }
                if($num>5){
                    $redis->handle()->close();
                    break;
                }
            }
            $redis = new Redis(['select' => 13]);
            $redis->handle()->expire($key,1800);
            $rand = $redis->handle()->zRevRange($key,0,100,true);
            $redis->handle()->close();
        }
        if(empty($rand)){
            $this->result('success', [], 200);
        }else{
            $result = [];
            $ip2region = new Ip2Region();
            foreach ($rand as $k=>$v){
                $info =$ip2region->memorySearch($k);
                if (!empty($info)) {
                    $province = explode('|', $info['region']);
                    if (!empty($province[2])) {
                        if(isset($result[$province[2]])){
                            $result[$province[2]]['value'] +=$v;
                        }else{
                            $result[$province[2]] = [
                                'name' => $province[2],
                                'value' => $v,
                            ];
                        }
                    }
                }
            }
            array_multisort(array_column($result,"value"),SORT_DESC,$result);
            $result = array_slice($result, 0, 10);
            $this->result('success', $result, 200);
        }

//        $offset = 0;
//        $result = [];
//        $num = 0;
//        while (true){
//            $num++;
//            $cache = Db::table($views_table)->where($where)
//                ->order("create_time","DESC")
//                ->group('ip')
//                ->cache(true, 1800)
//                ->limit($offset,1000)
//                ->column("id,ip,count(ip) as count_id");
//            $offset +=1000;
//            $ip2region = new Ip2Region();
//            foreach ($cache as $k=>$v){
//                $info =$ip2region->memorySearch($v['ip']);
//                if (!empty($info)) {
//                    $province = explode('|', $info['region']);
//                    if (!empty($province[2])) {
//                        if(isset($result[$province[2]])){
//                            $result[$province[2]]['value'] +=$v['count_id'];
//                        }else{
//                            $result[$province[2]] = [
//                                'name' => $province[2],
//                                'value' => $v['count_id'],
//                            ];
//                        }
//                    }
//                }
//            }
////            $views = yeildArray($cache);
////            if(is_array($views)){
////                $result = array_merge($result,$views);
////            }
//            if(count($cache)<800){
//                break;
//            }
//            if($num>5){
//                break;
//            }
//        }
////            ->select();
////        $ip2region = new Ip2Region();
////        $result = [];
////        if ($views) {
////            foreach ($views as $key => $val) {
////                $info =$ip2region->memorySearch($val['ip']);
//////                $info = $ip2region->btreeSearch($val['ip']);
////                if (!empty($info)) {
////                    $province = explode('|', $info['region']);
////                    if (!empty($province[2])) {
////                        $result[] = [
////                            'name' => $province[2],
////                            'value' => $val['count_id'],
////                        ];
////                    }
////                }
////            }
////        }
////        $tmp = [];
////        foreach ($result as $v) {
////            if (!isset($tmp[$v['name']])) {
////                $tmp[$v['name']]['name'] = $v['name'];
////                $tmp[$v['name']]['value'] = $v['value'];
////            } else {
////                $tmp[$v['name']]['name'] = $v['name'];
////                $tmp[$v['name']]['value'] += $v['value'];
////            }
////        }
////        $out = array_values($tmp);
////        $last_names = array_column($out, 'value');
////        array_multisort($last_names, SORT_DESC, $out);
////        $result = array_slice($out, 0, 10);
//        if(empty($result)){
//            $this->result('success', [], 200);
//        }
//        array_multisort(array_column($result,"value"),SORT_DESC,$result);
//        $result = array_slice($result, 0, 10);
//        $this->result('success', $result, 200);
    }

    /**
     * 应用操作(会员端)
     * @ApiWeigh  3
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=true, description="应用id")
     * @ApiParams   (name="download_limit", type="Int", required=true, description="type为4时必填")
     * @ApiParams   (name="type", type="Int", required=true, description="操作类型;1-删除;2-上架;3-下架;4-下载次数限制")
     */
    public function appHandleMember()
    {
        $id = $this->request->param('id', 0);
        $type = $this->request->param('type', 0);
        if (!$id || !$type) {
            $this->error(__('Invalid parameters'));
        }
        //$user = ProxyUser::get(['id'=>$this->auth->id,'status'=>'normal']);
        $user = ProxyUser::where(['id'=>$this->auth->id,'status'=>'normal'])->find();
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }

        $app = ProxyApp::get(['id' => $id, 'user_id' => $this->auth->id]);
        if (!$app) {
            $this->error(__('应用不存在'));
        }
        switch ($type) {
            case 1;
//                $update['is_delete'] = 0;
                $update['is_download'] = 1; //软删除
                break;
            case 2;
//                $this->error(__('该应用已被系统自动下架，如需上架，请联系商务人员'));
                if ($app['status'] == -1) {
                    $this->error(__('该应用已被系统自动下架，如需上架，请联系商务人员'));
                }
                $auto = AppAuto::where(['app_id' => $id, 'user_id' => $this->auth->id, 'status' => 1])->whereTime('create_time', 'today')->count();
                if ($auto >= 1) {
                    $this->error(__('已触发自动下架规则,请修改异常预警设置'));
                }
                /**检测应用包是否存在**/
//                $oss_config = OssConfig::where("status", 1)->where("name", "oss")->find();
//                $oss_g_config = OssConfig::where("status", 1)->where("name", "g_oss")->find();
//                $oss = new Oss($oss_config);
//                $is_exit_oss = $oss->isExitFile($app["oss_path"]);
//                $g_oss = new Oss($oss_g_config);
//                $is_exit_oss_g = $g_oss->isExitFile($app["oss_path"]);
//                /***都没有包***/
//                if(!$is_exit_oss){
//                    $this->error('应用同步中，请两分钟后重试',$is_exit_oss);
//                }
//                if(!$is_exit_oss_g){
//                    $this->error('应用同步中，请两分钟后重试G',$is_exit_oss_g);
//                }
//                if (!$is_exit_oss && !$is_exit_oss_g) {
//                    if ($app["update_time"]) {
//                        if (strtotime($app["update_time"]) < (time() - 300)) {
//                            $this->error(__('应用预签名失败，请重新上传应用'));
//                        } else {
//                            $this->error(__('应用同步中，请两分钟后重试'));
//                        }
//                    } else {
//                        if (strtotime($app["create_time"]) < (time() - 300)) {
//                            $this->error(__('应用预签名失败，请重新上传应用'));
//                        } else {
//                            $this->error(__('应用同步中，请两分钟后重试'));
//                        }
//                    }
//                } elseif (!$is_exit_oss || !$is_exit_oss_g) {
//                    $this->error(__('应用同步中，请两分钟后重试'));
//                }
                $update['is_stop'] = 0;
                break;
            case 3;
//                $this->error(__('该应用已被系统自动下架，如需下架，请联系商务人员'));
//                if ($app['status'] == -1) {
//                    $this->error(__('该应用已被系统自动下架，如需上架，请联系商务人员'));
//                }
                AppAuto::where(['app_id' => $id, 'user_id' => $this->auth->id])->update(['status' => 0]);
                $update['is_stop'] = 1;
                break;
//            case 4;
//                $limit = $this->request->post('download_limit');
//                if ($limit < 0 || !is_numeric($limit)) {
//                    $this->error(__('Invalid parameters'));
//                }
//                $update['download_limit'] = intval($limit);
//                break;
            default;
                $this->error(__('Invalid parameters'));
                break;
        }
        Db::startTrans();
        try {
            ProxyApp::where(['id' => $id, 'user_id' => $this->auth->id])->update($update);
            Db::commit();
        } catch (\Exception $e) {
            $this->error('操作失败');
            Db::rollback();
        }
        /**
         * @todo APP缓存清理
         */
        Redis::del("app_tag:".$app["tag"],0);
        Redis::del("app_tag:".$app["tag"],4);
        Redis::del("app_short_url:".$app["short_url"],4);
        $this->result('success', [], 200);
    }

    /**
     * 检查应用是否需要更新
     * @ApiMethod (POST)
     * @ApiParams   (name="apptag", type="String", required=true, description="apptag")
     * @ApiReturnParams   (name="is_update", type="integer", required=true, sample="是否需要更新1是0否")
     * @ApiReturnParams   (name="is_force", type="integer", required=true, sample="是否强制更新1是0否")
     * @ApiReturnParams   (name="url", type="integer", required=true, sample="下载地址")
     */
    protected function checkUpdate()
    {
        $post = array_filter($this->request->post());
        if (empty($post['apptag'])) {
            $this->error('参数错误');
        }
        $data = explode('.', $post['apptag']);
        $appid = isset($data[0]) ? $data[0] : 0;
        $version = isset($data[1]) ? $data[1] : 0;
        if (!$appid || !$version) {
            $this->error('参数错误');
        }
        $app = ProxyApp::get(["id" => $appid, "is_delete" => 1]);
        $app_version = str_replace('.', '', $app["version_code"]);
        if (!$app) {
            $this->error('应用不存在');
        }
        if ($app["is_update"] == 1) {
            if ($app_version == $version) {
                $is_update = 0;
            } else {
                $is_update = 1;
            }
        } else {
            $is_update = 0;
        }
        $user = ProxyUser::get(["id" => $app['user_id']]);
        $proxy = ProxyUserDomain::get(["id" => $user['pid']]);
        $url = 'https://' . $proxy['download_url'] . '/' . $app['short_url'] . '.html';
        $result = [
            'name' => $app['name'],
            'is_update' => $is_update,
            'is_force' => $is_update == 1 ? $app['is_force'] : 0,
            'url' => $url,
        ];
        $this->success('ok', $result, 200);
    }

    /**
     * 检测是否安装
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    protected function checkInstall()
    {
        if ($this->request->isPost()) {
            $udid = $this->request->post('udid', null);
            $apptag = $this->request->post('apptag', null);
            if (empty($udid) || empty($apptag)) {
                $this->error('参数错误', null);
            }
            $apptag = explode('.', $apptag);
            $appid = $apptag[0];
            $app = ProxyApp::get($appid);
            if (empty($app)) {
                $this->error('应用不存在', null);
            }
            $user = ProxyUser::get($app["user_id"]);
            $table = getTable("proxy_download", $user["pid"]);
            $download = Db::table($table)->where(['udid' => $udid, 'tag' => $app['tag']])->find();
            if (!empty($download)) {
//                $oss = new Oss();
                Db::table($table)->where("id", $download["id"])->update(['is_open' => 1]);
//                if($oss->isExitFile($download['app_path'])){
//                    $oss->ossDelete($download['app_path']);
//                }
            }
            $this->success('success', null, 200);
        }
    }

    /**
     * 无缝更新
     * @throws \think\exception\DbException
     */
    protected function seamlessUpdate()
    {
        if ($this->request->isPost()) {
            $udid = $this->request->post('udid', null);
            $apptag = $this->request->post('apptag', null);
            $host = $this->request->host();
            if (empty($udid) || empty($apptag)) {
                $this->error('参数错误', null);
            }
            $apptag = explode('.', $apptag);
            $appid = $apptag[0];
            $app = ProxyApp::get($appid);
            if (empty($app)) {
                $this->error('应用不存在', null);
            }
            $iosPackage = new IosPackage();
            $callback_url = Config::where('name', 'proxy_download_callback_url')->value('value');
            $callback = $callback_url . '/proxy/' . $app['tag'] . '/' . $udid;
            $bundle = 'com.' . $app['tag'] . '.www';
            $cache_plist = $iosPackage->addTemporaryPList($app['name'], $udid, $bundle, $app["icon"], $callback);
            $save = "cache-uploads/download/" . date('Ymd') . "/" . $udid . ".plist";
            $oss_config = OssConfig::where("status", 1)
                ->where("name", "proxy_g_oss_read")
                ->find();
            $oss = new Oss($oss_config);
            if ($oss->ossUpload($cache_plist, $save)) {
                $down_url = 'itms-services://?action=download-manifest&url=' . $oss->oss_url() . $save;
                $this->http_async_request(config('url_ios_sign'), ['udid' => $udid, 'tag' => $app['tag']]);
                $this->success('success', ['url' => $down_url], 200);
            } else {
                $this->error('oss-fail', null);
            }
        }
    }

    /**
     * 安卓地址更新
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="apk_url", type="String", required=true, description="安卓应用上传地址")
     */
    public function apkUrl()
    {
        $apk_url = $this->request->post('apk_url');
        $app_id = $this->request->post('app_id');
        if (empty($app_id)) {
            $this->error(__('参数错误'));
        }

        $user = ProxyUser::get(['id'=>$this->auth->id,'status'=>'normal']);
//        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }

        $app = ProxyApp::where(['user_id' => $this->auth->id, 'id' => $app_id])->find();
        if (empty($app)) {
            $this->error(__('应用不存在'));
        }
        $proxy = ProxyUserDomain::get(['user_id' => $this->auth->pid]);
        $app_apk_url = 'https://' . $proxy['download_url'] . '/' . $app['short_url'] . '.html';
        if ($app_apk_url == $apk_url) {
            $this->result('success', '保存成功', 200);
        }
        try {
            /**APK上传**/
            if (!empty($apk_url) && !strstr($apk_url, 'http')) {
                $ip = $this->request->ip();
                $ip2 = new Ip2Region();
                $ip_address = $ip2->binarySearch($ip);
                $address = explode('|', $ip_address['region']);
                if ($address[0] == "中国" && !in_array($address[2], ["澳门", '香港', "台湾省","台湾"])) {
                    $sign_url = Config::where("name", "ipa_parsing")->value("value");
                } else {
//                    $sign_url = Config::where("name", "g_ipa_parsing")->value("value");
                    $sign_url = "35.227.214.161";
                }
                $url = "http://".$sign_url."/index/apkoss";
                $oss_path = 'apk/' . date("YmdHi") . "/" . $app['tag'] . '.apk';
                $result = $this->http_request($url, ['path' => $apk_url, 'oss_path' => $oss_path]);
                $htt_re = json_decode($result, true);
                if (isset($htt_re["result"]['code']) && $htt_re["result"]['code'] == 1) {
                    $apk_url = $oss_path;
                }
//                else{
//                    $this->error('保存失败');
//                }
            }
            ProxyApp::update(["id"=>$app_id,'apk_url' => $apk_url]);
        } catch (Exception $e) {
            Log::write($e->getTraceAsString());
            $this->error(__('保存失败'));
        }
        /**
         * @todo APP缓存清理
         */
        try {
            Redis::del("app_tag:".$app["tag"],0);
            Redis::del("app_tag:".$app["tag"],4);
            Redis::del("app_short_url:".$app["short_url"],4);
            if(!empty($app["apk_url"])&&!strstr($app["apk_url"],"http")){
                $md5_sign = md5($app["apk_url"]."8^6kJ6MYz_7SwPLZlxeC");
                $re = $this->http_request("http://34.171.45.237/index/apk_del", ['key' => $app["apk_url"], 'sign' => $md5_sign]);
            }
        }catch (Exception $e) {
            Log::write('调用APP缓存清理出错：'.$e->getTraceAsString());
        }
        $this->result('success', '保存成功', 200);
    }

    /**
     * 应用异常预警
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=true, description="应用ID")
     * @ApiReturnParams (name="down_frequency", type="integer", required=true, description="下载预警监测频率")
     * @ApiReturnParams (name="down_times", type="integer", required=true, description="下载预警次数")
     * @ApiReturnParams (name="auto_close", type="integer", required=true, description="自动下架检测频率")
     * @ApiReturnParams (name="auto_times", type="integer", required=true, description="自动下架次数")
     * @ApiReturnParams (name="day_consume", type="integer", required=true, description="每日消费限制")
     * @ApiReturnParams (name="day_times", type="integer", required=true, description="每日消费下载次数")
     * @ApiReturnParams (name="download_limit", type="integer", required=true, description="下载次数限制")
     */
    public function appEarlyWarningInfo()
    {
        $id = $this->request->param('id');
        if (!$id) {
            $this->error(__('Invalid parameters'));
        }
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }
        $app = ProxyApp::get(['id' => $id, 'user_id' => $this->auth->id]);
        if (empty($app)) {
            $this->error(__('应用不存在'));
        }
        $info = appEarlyWarning::get(['app_id' => $id, 'user_id' => $this->auth->id]);
        //新建默认记录
        if (empty($info)) {
            $data = [
                'app_id' => $id,
                'user_id' => $this->auth->id,
                'down_frequency' => 0,//预警检测频率
                'down_times' => 0,//预警次数
                'auto_close' => 0,//自动下架检测频率
                'auto_times' => 0,//自动下架次数
                'day_consume' => 0,//每日消费限制
                'day_times' => 0,//每日消费下载次数
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
                'is_auto_start' => 0,
                'start_time' => '',
                'stop_time' => '',
            ];
            $info = appEarlyWarning::create($data);
        }
        $info['download_limit'] = $app['download_limit'] ?? 0;//下载次数限制
        $info['is_vaptcha'] = $app['is_vaptcha'] ?? 0;//验证
        $this->result('success', $info, 200);
    }

    /**
     * 应用异常预警设置
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=true, description="应用ID")
     * @ApiParams (name="auto_close", type="integer", required=true, description="自动下架检测频率")
     * @ApiParams (name="auto_times", type="integer", required=true, description="自动下架次数")
     * @ApiParams (name="day_consume", type="integer", required=true, description="每日消费限制")
     * @ApiParams (name="day_times", type="integer", required=true, description="每日消费下载次数")
     * @ApiParams (name="download_limit", type="integer", required=true, description="下载次数限制")
     */
    public function appEarlyWarning()
    {
        $post = $this->request->post();
        if (empty($post['id'])) {
            $this->error(__('Invalid parameters'));
        }
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }
        $app = ProxyApp::get(['id' => $post['id'], 'user_id' => $this->auth->id]);
        if (empty($app)) {
            $this->error(__('应用不存在'));
        }

        $info = appEarlyWarning::get(['app_id' => $post['id'], 'user_id' => $this->auth->id]);
        $rule = [
            'auto_close' => 'require|egt:0|integer',
            'auto_times' => 'require|egt:0|integer',
            'day_consume' => 'require|egt:0|number',
            'day_times' => 'require|egt:0|integer',
            'download_limit' => 'require|egt:0|integer',
        ];
        $msg = [
            'auto_close.require' => '自动下架检测频率必填',
            'auto_close.egt' => '自动下架检测频率必须大于等于0',
            'auto_close.integer' => '自动下架检测频率设置错误',
            'auto_times.require' => '自动下架下载次数必填',
            'auto_times.egt' => '自动下架下载次数必须大于等于0',
            'auto_times.integer' => '自动下架下载次数设置错误',
            'day_consume.require' => '每日消费限制金额必填',
            'day_consume.egt' => '每日消费限制金额必须大于等于0',
            'day_consume.number' => '每日消费限制金额设置错误',
            'day_times.require' => '每日消费限制下载必填',
            'day_times.egt' => '每日消费限制下载次数必须大于等于0',
            'day_times.number' => '每日消费限制下载次数设置错误',
            'download_limit.require' => '下载次数限制必填',
            'download_limit.egt' => '下载次数限制必须大于等于0',
            'download_limit.number' => '下载次数限制设置错误',
        ];
        $data = [
            'auto_close' => $post['auto_close'],
            'auto_times' => $post['auto_times'],
            'day_consume' => $post['day_consume'],
            'day_times' => $post['day_times'],
            'download_limit' => $post['download_limit'],
        ];
        $validate = new Validate($rule, $msg);
        $result = $validate->check($data);
        if (!$result) {
            $this->error(__($validate->getError()));
        }
        $arr = [
            'down_frequency' => 0,//预警检测频率
            'down_times' => 0,//预警次数
            'auto_close' => $post['auto_close'] ?? 0,//自动下架检测频率
            'auto_times' => $post['auto_times'] ?? 0,//自动下架次数
            'day_consume' => $post['day_consume'] ?? 0,//每日消费限制
            'day_times' => $post['day_times'] ?? 0,//每日消费下载次数
            'update_time' => date('Y-m-d H:i:s'),
        ];
        if(!empty($post["is_auto_start"])&&$post["is_auto_start"]==1){
//            if(empty($post["start_time"])||empty($post["stop_time"])){
//                $this->error("请选择上下架时间");
//            }
            $arr["is_auto_start"]=1;
            $arr["start_time"]=trim($post["start_time"]);
            $arr["stop_time"]=trim($post["stop_time"]);
        }else{
            $arr["is_auto_start"]=0;
            $arr["start_time"]=trim($post["start_time"]);
            $arr["stop_time"]=trim($post["stop_time"]);
        }
        try {
            if (empty($info)) {
                $arr = array_merge($arr, ['create_time' => date('Y-m-d H:i:s'), 'user_id' => $this->auth->id, 'app_id' => $post['id']]);
                appEarlyWarning::create($arr);
            } else {
                $appUp = [
                    'is_vaptcha' => $post['is_vaptcha'],
                ];
                $appUp['download_limit'] = $post['download_limit'] ?? $app['download_limit'];
                ProxyApp::update($appUp, ['id' => $post['id']]);
                appEarlyWarning::update($arr, ['app_id' => $post['id']]);
            }
            AppAuto::where(['app_id' => $post['id'], 'user_id' => $this->auth->id])->update(['status' => 0]);
        } catch (\Exception $e) {
            $this->error(__('保存失败'));
        }
        $this->result('保存成功', [], 200);
    }

    /**
     * 应用证书文件上传
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="app_id", type="Int", required=true, description="应用ID")
     * @ApiParams   (name="cert_url", type="String", required=true, description="应用证书")
     * @ApiParams   (name="password", type="String", required=false, description="证书密码")
     */
    protected function pushCert()
    {
        $app_id = $this->request->post('app_id');
        $file_name = $this->request->post('cert_url');
        $password = $this->request->post('password');
        if (empty($file_name) || empty($app_id)) {
            $this->error(__('参数错误'));
        }
        //$user = ProxyUser::get(['id'=>$this->auth->id,'status'=>'normal']);
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }
        $app = ProxyApp::get(['id' => $app_id, 'user_id' => $this->auth->id]);
        if (empty($app)) {
            $this->error(__('应用不存在'));
        }
        $outpath = ROOT_PATH . '/public/apnscert/';//生成pem保存地址
        if (!is_dir($outpath)) {
            mkdir($outpath, 0777, true);
        }
        $save_name = $outpath . $app['tag'] . '.pem'; //输出的pem证书
        $ip = $this->request->ip();
        $ip2 = new Ip2Region();
        $ip_address = $ip2->binarySearch($ip);
        $address = explode('|', $ip_address['region']);
        if ($address[0] == "中国" && !in_array($address[2], ["澳门", '香港', "台湾省","台湾"])) {
            $oss_config = OssConfig::where("status", 1)
                ->where("name", "proxy_oss")
                ->find();
        } else {
            $oss_config = OssConfig::where("status", 1)
                ->where("name", "proxy_g_oss")
                ->find();
        }
        $oss_read_config = OssConfig::where("status", 1)
            ->where("name", "proxy_g_oss_read")
            ->find();
        $oss = new Oss($oss_config);
        $cahe_img = $outpath . $file_name;//oss下载保存地址

        if ($oss->isExitFile($file_name) && $oss->ossDownload($file_name, $cahe_img)) {
            $exec = "cd $outpath && openssl pkcs12 -in $file_name -out $save_name  -nodes -password pass:$password";
            exec($exec, $log, $status);
            if ($status == 0) {
                $save_path = 'uploads/pushcert/' . date('Ymd') . '/' . $app['tag'] . '.pem';
                $cert_oss = new Oss($oss_read_config);
                $result = $cert_oss->ossUpload($save_name, $save_path);
                if (!$result) {
                    $this->error(__('证书上传失败'));
                }
                try {
                    ProxyApp::update(['cert_path' => $save_path], ['id' => $app_id]);
                } catch (\Exception $e) {
                    $this->error(__('请校验您的证书和密码'));
                }
                @unlink($cahe_img);
                @unlink($save_name);
                $this->success(__('证书上传成功'), [], 200);
            } else {
                $this->error(__('请校验您的证书和密码'));
            }
        } else {
            $this->error(__('证书上传失败'));
        }
    }

    /**
     * 创建下载码
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="app_id", type="Int", required=true, description="应用ID")
     * @ApiParams   (name="number", type="Int", required=true, description="个数")
     * @ApiParams   (name="num", type="Int", required=true, description="使用次数")
     */
    public function addDownloadCode()
    {
        $app_id = $this->request->param('app_id');
        $number = $this->request->param('number');
        $num = $this->request->param('num');
        $rule = [
            'app_id' => 'require',
            'number' => 'require|between:1,1000|integer',
            'num' => 'require|between:1,10000|integer',
        ];
        $msg = [
            'app_id.require' => '未找到相关应用',
            'number.require' => '创建个数必填',
            'number.between' => '创建个数只能在1-1000之间',
            'number.integer' => '创建个数填写有误',
            'num.require' => '可使用次数必填',
            'num.between' => '可使用次数只能在1-10000之间',
            'num.integer' => '可使用次数填写错误',
        ];
        $data = [
            'app_id' => $app_id,
            'number' => $number,
            'num' => $num,
        ];
        $validate = new Validate($rule, $msg);
        $result = $validate->check($data);
        if (!$result) {
            $this->error(__($validate->getError()));
        }
        //$user = ProxyUser::get(['id'=>$this->auth->id,'status'=>'normal']);
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }
        $app = ProxyApp::where(['id' => $app_id, 'user_id' => $this->auth->id])->find();
        if (empty($app)) {
            $this->error('应用不存在');
        }
        for ($i = 1; $i <= $number; $i++) {
            $code = strtolower(get_rand_str(6, 0, 1));
            $is_exit = DownloadCode::where(['code' => $code, 'app_id' => $app_id, 'user_id' => $this->auth->id])->find();
            if ($is_exit) {
                $code = strtolower(get_rand_str(6, 0, 1));
            }
            $data = [
                'user_id' => $this->auth->id,
                'app_id' => $app_id,
                'code' => $code,
                'num' => $num,
                'status' => 0,
                'create_time' => date('Y-m-d H:i:s'),
            ];
            $res[] = $data;
        }
        $result = DownloadCode::insertAll($res);
        if ($result) {
            $this->result(__('创建下载码成功'), null, 200);
        } else {
            $this->error(__('创建下载码失败'));
        }
    }

    /**
     * 下载码编辑
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=true, description="下载码id")
     * @ApiParams   (name="num", type="Int", required=true, description="可使用次数")
     * @ApiParams   (name="type", type="Int", required=true, description="操作类型;1-开启;2-关闭;3-修改可使用次数")
     */
    public function downloadCodeHandle()
    {
        $id = $this->request->param('id', 0);
        $type = $this->request->param('type', 0);
        if (!$id || !$type) {
            $this->error(__('Invalid parameters'));
        }
        //$user = ProxyUser::get(['id'=>$this->auth->id,'status'=>'normal']);
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }
        $code = DownloadCode::get(['id' => $id, 'user_id' => $this->auth->id]);
        if (!$code) {
            $this->error(__('下载码不存在'));
        }
        switch ($type) {
            case 1;
                $update['status'] = 1;
                break;
            case 2;
                $update['status'] = 0;
                break;
            case 3;
                $num = $this->request->param('num');
                if ($num < 0 || !is_numeric($num)) {
                    $this->error(__('Invalid parameters'));
                }
                if ($num < $code['used']) {
                    $this->error('可使用次数不能小于已使用次数');
                }
                $update['num'] = intval($num);
                break;
            case 4;
                Db::startTrans();
                try {
                    DownloadCode::where(['id' => $id, 'user_id' => $this->auth->id])->delete();
                    Db::commit();
                } catch (\Exception $e) {
                    $this->error('操作失误');
                    Db::rollback();
                }
                break;
            default;
                $this->error(__('Invalid parameters'));
                break;
        }
        if ($type != 4) {
            Db::startTrans();
            try {
                DownloadCode::where(['id' => $id, 'user_id' => $this->auth->id])->update($update);
                Db::commit();
            } catch (\Exception $e) {
                $this->error('操作失误');
                Db::rollback();
            }
        }
        $this->result('success', [], 200);
    }

    /**
     * 下载码批量开启/禁用
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="ids", type="String", required=true, description="下载码ids英文逗号分隔")
     * @ApiParams   (name="type", type="Int", required=true, description="操作类型;1-开启;2-关闭")
     * @ApiParams   (name="status", type="Int", required=true, description="操作类型;1-是;2-否")
     */
    public function downloadCodeMulti()
    {
        $ids = $this->request->param('ids');
        $type = $this->request->param('type');
        $status = $this->request->post('status');
        $app_id = $this->request->param('app_id');

        if (!$type) {
            $this->error(__('Invalid parameters'));
        }

        //$user = ProxyUser::get(['id'=>$this->auth->id,'status'=>'normal']);
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }

        if ($status == 1) {
            //所有
            if (!$app_id) {
                $this->error(__('Invalid parameters'));
            }
            $where = [
                'user_id' => $this->auth->id,
                'app_id' => $app_id,
                'is_delete' => 0,
            ];
        } else {
            if (!$ids) {
                $this->error(__('Invalid parameters'));
            }
            $where = [
                'user_id' => $this->auth->id,
                'id' => ['in', $ids],
                'is_delete' => 0,
            ];
        }

        if ($type == 1) {
            $update['status'] = 1;
        } else {
            $update['status'] = 0;
        }
        $list = DownloadCode::where($where)->select();
        $count = 0;
        Db::startTrans();
        try {
            foreach ($list as $index => $item) {
                $count += $item->save($update);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            $this->error('操作失败,请刷新重试');
        }
        if ($count) {
            $this->success('success', null, 200);
        } else {
            $this->error(__('操作失败,请刷新重试'));
        }
    }

    /**
     * 下载码列表
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="app_id", type="Int", required=true, description="应用ID")
     * @ApiParams   (name="keywords", type="String", required=false, description="应用名称")
     * @ApiParams   (name="order", type="String", required=false, description="排序num,used,surplus,create_time")
     * @ApiParams   (name="sort", type="String", required=false, description="asc,desc")
     * @ApiParams   (name="page", type="Int", required=false, description="当前页")
     * @ApiParams   (name="page_size", type="Int", required=false, description="每页显示条数")
     * @ApiReturnParams (name="code", type="string", required=true, description="下载码")
     * @ApiReturnParams (name="num", type="integer", required=true, description="可使用次数")
     * @ApiReturnParams (name="used", type="integer", required=true, description="已使用次数")
     * @ApiReturnParams (name="surplus", type="integer", required=true, description="剩余次数")
     * @ApiReturnParams (name="status", type="integer", required=true, description="状态：1开启0关闭")
     * @ApiReturnParams (name="currentPage", type="Int", required=true, description="当前页")
     */
    public function downloadCodeList()
    {
        $keywords = $this->request->param('keywords');
        $app_id = $this->request->param('app_id');
        $order = $this->request->param('order', 'create_time');
        $sort = $this->request->param('sort', 'asc');
        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 10);
        if (!$app_id) {
            $this->error('获取列表失败，请刷新重试');
        }
        //$user = ProxyUser::get(['id'=>$this->auth->id,'status'=>'normal']);
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }
        if ($order == 'surplus') {
            $order = 'create_time';
        }
        if (intval($page) < 1) $page = 1;
        $offset = ($page - 1) * $pageSize;
        $where = [
            'user_id' => $this->auth->id,
            'app_id' => $app_id,
            'is_delete' => 0,
        ];
        $keywords && $where['code'] = ['like', '%' . $keywords . '%'];
        $total = DownloadCode::where($where)->count('id');
        $list = DownloadCode::where($where)
            ->order($order, $sort)
            ->order('id', 'desc')
            ->limit($offset, $pageSize)
            ->select();
        foreach ($list as $k => $v) {
            $list[$k]['surplus'] = bcsub($v['num'], $v['used']);
            $list[$k]['code'] = strtoupper($v['code']);
        }
        if ($order == 'surplus') {
            array_multisort($list, 'surplus', $sort);
        }
        $result = [
            'total' => $total,
            'currentPage' => $page,
            'list' => array_values($list)
        ];
        $this->result('success', $result, 200);
    }

    /**
     * 下载码批量删除
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="ids", type="String", required=true, description="下载码ids英文逗号分隔")
     * @ApiParams   (name="app_id", type="Int", required=true, description="app_id应用ID")
     * @ApiParams   (name="type", type="Int", required=true, description="是否删除所有")
     */
    public function downloadCodeDel()
    {
        $ids = $this->request->param('ids');
        $app_id = $this->request->param('app_id');
        $type = $this->request->param('type');

        //$user = ProxyUser::get(['id'=>$this->auth->id,'status'=>'normal']);
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }

        if ($type) {
            if (!$app_id) {
                $this->error(__('Invalid parameters'));
            }
            $where = [
                'user_id' => $this->auth->id,
                'app_id' => $app_id,
                'is_delete' => 0,
            ];
        } else {
            if (!$ids) {
                $this->error(__('Invalid parameters'));
            }
            $where = [
                'user_id' => $this->auth->id,
//                'app_id'=>$app_id,
                'id' => ['in', $ids],
                'is_delete' => 0,
            ];
        }
        $update=[
            'is_delete' => 1,
            'delete_time' => date("Y-m-d H:i:s"),
        ];

        $list = DownloadCode::where($where)->select();
        $count = 0;
        Db::startTrans();
        try {
            foreach ($list as $index => $item) {
                $count += $item->save($update);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            $this->error('操作失败,请刷新重试');
        }
        if ($count) {
            $this->success('success', null, 200);
        } else {
            $this->error(__('操作失败,请刷新重试'));
        }
    }

    /**
     * 下载码导出
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="app_id", type="Int", required=true, description="应用ID")
     */
    public function export()
    {

        //$user = ProxyUser::get(['id'=>$this->auth->id,'status'=>'normal']);
        $ids = $this->request->param('ids');
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }

        $app_id = $this->request->param('app_id');
        if (!$app_id) {
            $this->error('参数错误');
        }
        $where = [
            'c.user_id' => $this->auth->id,
            'c.app_id' => $app_id,
            'c.is_delete' => 0,
        ];
        if(!empty($ids)){
            $where["c.id"] = ['in', $ids];
        }
        $model = new DownloadCode();
        $count = $model->alias('c')
            ->join('proxy_app a', 'c.app_id = a.id')
            ->where($where)
            ->count('c.id');
        if ($count<=0) {
            $this->error('暂无下载码');
        }

        $save_path = ROOT_PATH."/runtime/uploads/".date("Ymd");
        if(!is_dir($save_path)){
            mkdir($save_path,0777,true);
        }
        $file_name = date('Y-m-d', time()) . rand(1000, 9999).'.xlsx';
        $config = ['path' => $save_path];
        $excel  = new \Vtiful\Kernel\Excel($config);

        // 第三个参数 False 即为关闭 ZIP64
        $fileObject = $excel->constMemory($file_name, NULL, false);
//        $fileHandle = $fileObject->getHandle();

//        $format    = new \Vtiful\Kernel\Format($fileHandle);
//        $boldStyle = $format->bold()->toResource();
        $fp = $fileObject->header(['应用名称', '下载码','可用次数','已使用','剩余次数','状态']);
        $rows = 1;
        $page = 1;
        while (true){
            $offset = ($page-1)*1000;
            $list = $model->alias('c')
                ->join('proxy_app a', 'c.app_id = a.id')
                ->where($where)
                ->field('c.*,a.name')
                ->order('c.create_time', 'desc')
                ->limit($offset,1000)
                ->select();
            if(empty($list)){
                break;
            }
            foreach ($list as $v) {
                $fp->insertText($rows,0,$v['name']);
                $fp->insertText($rows,1,$v['code']);
                $fp->insertText($rows,2,$v['num']);
                $fp->insertText($rows,3,$v['used']);
                $fp->insertText($rows,4,bcsub($v['num'], $v['used']));
                $fp->insertText($rows,5,($v['status'] ? '开启' : '关闭'));
                $rows++;
            }
            $page++;
        }
        $filePath = $fp->output();
        $file = fopen ( $save_path."/" . $file_name, "rb" );

        //告诉浏览器这是一个文件流格式的文件
        Header ( "Content-type: application//vnd.ms-excel" );
        //请求范围的度量单位
        Header ( "Accept-Ranges: bytes" );
        //Content-Length是指定包含于请求或响应中数据的字节长度
        Header ( "Accept-Length: " . filesize ($save_path."/" . $file_name) );
        //用来告诉浏览器，文件是可以当做附件被下载，下载后的文件名称为$file_name该变量的值。
        Header ( "Content-Disposition: attachment; filename=" . $file_name );
        //读取文件内容并直接输出到浏览器
        echo fread ( $file, filesize ($save_path."/" . $file_name) );
        fclose ($file);
        exit ();

//        header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
//        header('Content-Disposition: attachment;filename="' . $file_name . '"');
//        header('Content-Length: ' . filesize($filePath));
//        header('Content-Transfer-Encoding: binary');
//        header('Cache-Control: must-revalidate');
//        header('Cache-Control: max-age=0');
//        header('Pragma: public');
//
//        ob_clean();
//        flush();
//        exit();
//        $spreadsheet = new Spreadsheet();
//        $sheet = $spreadsheet->getActiveSheet();
//        $sheet->setTitle('code_list');
//        $spreadsheet->getActiveSheet()->setTitle('Hello');
//        //设置第一行小标题
//        $k = 1;
//        $sheet->setCellValue('A' . $k, '应用名称');
//        $sheet->setCellValue('B' . $k, '下载码');
//        $sheet->setCellValue('C' . $k, '可用次数');
//        $sheet->setCellValue('D' . $k, '已使用');
//        $sheet->setCellValue('E' . $k, '剩余次数');
//        $sheet->setCellValue('F' . $k, '状态');
//        $where = [
//            'c.user_id' => $this->auth->id,
//            'c.app_id' => $app_id,
//        ];
//        if(!empty($ids)){
//            $where["c.id"] = ['in', $ids];
//        }
//        $model = new DownloadCode();
//        $list = $model->alias('c')
//            ->join('proxy_app a', 'c.app_id = a.id')
//            ->where($where)
//            ->field('c.*,a.name')
//            ->order('c.create_time', 'desc')
//            ->select();
//        if (empty($list)) {
//            $this->error('暂无下载码');
//        }
//        foreach ($list as $k => $v) {
//            $info[$k]['name'] = $v['name'];
//            $info[$k]['code'] = $v['code'];
//            $info[$k]['num'] = $v['num'];
//            $info[$k]['used'] = $v['used'];
//            $info[$k]['surplus'] = bcsub($v['num'], $v['used']);
//            $info[$k]['status'] = $v['status'] ? '开启' : '关闭';
//        }
//        $spreadsheet->getActiveSheet()->getColumnDimension('A')->setWidth(20);
//        $k = 2;
//        foreach ($info as $key => $value) {
//            $sheet->setCellValue('A' . $k, $value['name']);
//            $sheet->setCellValue('B' . $k, $value['code']);
//            $sheet->setCellValue('C' . $k, $value['num']);
//            $sheet->setCellValue('D' . $k, $value['used']);
//            $sheet->setCellValue('E' . $k, $value['surplus']);
//            $sheet->setCellValue('F' . $k, $value['status']);
//            $k++;
//        }
//        $file_name = date('Y-m-d', time()) . rand(1000, 9999);
//        //第一种保存方式
//        /*$writer = new Xlsx($spreadsheet);
//        //保存的路径可自行设置
//        $file_name = '../'.$file_name . ".xlsx";
//        $writer->save($file_name);*/
//        //第二种直接页面上显示下载
//        $file_name = $file_name . ".xlsx";
//        header('Content-Type: application/vnd.ms-excel');
//        header('Content-Disposition: attachment;filename="' . $file_name . '"');
//        header('Cache-Control: max-age=0');
//        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
//        //注意createWriter($spreadsheet, 'Xls') 第二个参数首字母必须大写
//        $writer->save('php://output');
    }

    public function export_v2()
    {
        $ids = $this->request->param('ids');
        $user = ProxyUser::get($this->auth->id);
        //新增对会员信息不存在的判断
        if (empty($user)) {
            //代表登录用户信息不存在
            $this->error(__('会员不存在'));
        }

        $app_id = $this->request->param('app_id');
        if (!$app_id) {
            $this->error('参数错误');
        }
        $where = [
            'c.user_id' => $this->auth->id,
            'c.app_id' => $app_id,
            'c.is_delete' => 0,
        ];
        if(!empty($ids)){
            $where["c.id"] = ['in', $ids];
        }
        $model = new DownloadCode();
        $count = $model->alias('c')
            ->join('proxy_app a', 'c.app_id = a.id')
            ->where($where)
            ->count('c.id');
        if ($count<=0) {
            $this->error('暂无下载码');
        }
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('下载码');
        $spreadsheet->getActiveSheet()->setTitle('下载码');
        //设置第一行小标题
        $k = 1;
        $sheet->setCellValue('A' . $k, '应用名称');
        $sheet->setCellValue('B' . $k, '下载码');
        $sheet->setCellValue('C' . $k, '可用次数');
        $sheet->setCellValue('D' . $k, '已使用');
        $sheet->setCellValue('E' . $k, '剩余次数');
        $sheet->setCellValue('F' . $k, '状态');

        //$spreadsheet->getActiveSheet()->getColumnDimension('A')->setWidth(20);
        $k = 2;
        $page = 1;
        while (true){
            $offset = ($page-1)*1000;
            $list = $model->alias('c')
                ->join('proxy_app a', 'c.app_id = a.id')
                ->where($where)
                ->field('c.*,a.name')
                ->order('c.create_time', 'desc')
                ->limit($offset,1000)
                ->select();
            if(empty($list)){
                break;
            }
            foreach ($list as $v) {
                $sheet->setCellValue('A' . $k, $v['name']);
                $sheet->setCellValue('B' . $k, $v['code']);
                $sheet->setCellValue('C' . $k, $v['num']);
                $sheet->setCellValue('D' . $k, $v['used']);
                $sheet->setCellValue('E' . $k, bcsub($v['num'], $v['used']));
                $sheet->setCellValue('F' . $k, ($v['status'] ? '开启' : '关闭'));
                $k++;
            }
            /*foreach ($list as $v) {
                $fp->insertText($rows,0,$v['name']);
                $fp->insertText($rows,1,$v['code']);
                $fp->insertText($rows,2,$v['num']);
                $fp->insertText($rows,3,$v['used']);
                $fp->insertText($rows,4,bcsub($v['num'], $v['used']));
                $fp->insertText($rows,5,($v['status'] ? '开启' : '关闭'));
                $rows++;
            }*/
            $page++;
        }
        
        $file_name = date('Y-m-d', time()) . rand(1000, 9999);
        //第一种保存方式
        $writer = new Xlsx($spreadsheet);
        //保存的路径可自行设置
        $save_name = ROOT_PATH . '/runtime/upload/' . $file_name . ".xlsx";
        $writer->save($save_name);
        $save = "cache-uploads/xlsx/" . $file_name . ".xlsx";;
        //上传到OSS服务器
        $sign = md5($save."uSl!I~vGjYQHJUXjTxUO");
        $client=curl_client('post',[
            'file'=>new \CURLFile($save_name),
            'sign'=>$sign,
            'key'=>$save
        ],'http://34.135.101.133:85/index/cache_upload');
        $client['code']=$client['code']??0;
        if($client&&$client['code']==200){
            @unlink($save_name);
            $ip2 = new Ip2Region();
            $ip_address = $ip2->binarySearch($this->request->ip());
            $address = explode('|', $ip_address['region']);
            if ($address[0] == "中国" && !in_array($address[2], ["澳门", '香港', "台湾省","台湾"])) {
                $xlsx_url = Config::where('name', 'proxy_zh_oss_public_url')->value('value');
            } else {
                $xlsx_url = Config::where('name', 'proxy_en_oss_public_url')->value('value');
            }
            $xlsx_url = $xlsx_url .'/'. $save;
        }else{
            $this->error(__('导出失败'));
        }
        //保存到OSS
        /*$oss_read_config = OssConfig::where("status",1)
            ->where("name","g_oss_read")
            ->find();
        $oss = new Oss($oss_read_config);
        if ($oss->ossUpload($save_name, $save)) {
            @unlink($save_name);
            $xlsx_url = $oss->oss_url() . $save;
        } else {
            $this->error(__('导出失败'));
        }*/
        $this->success('success', $xlsx_url, 200);
    }
    
    /**
     * 次数统计
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="user_id", type="Int", required=false, description="赛选用户ID")
     * @ApiParams   (name="type", type="Int", required=false, description="类型 1 今日 2累计")
     */
    public function statics()
    {
        $sub_user_id = $this->request->param("user_id", null);
        $type = $this->request->param("type", 1);
        $user_id = $this->auth->id;
        $user = ProxyUser::get($user_id);
        if (empty($user) || $user["is_proxy"] != 1) {
            $this->error("您不是管理员，无权限！");
        }
        //   $sub_user_id = ProxyUser::where("pid",$user["id"])->column("id");
        $bale_rate_table = getTable("proxy_bale_rate", $user_id);
        $list_where = [
            "pid" => $user_id,
            "status" => 1
        ];
        if ($sub_user_id) {
            $list_where["user_id"] = $sub_user_id;
        }
        /***今日消耗次数**/
        $today_recharge = ProxyRechargeLog::where("pid", $user_id)
            ->where("type", 3)
            ->where("status", 1)
            ->whereTime("create_time", "d")
            ->cache(true,600)
            ->sum("num");
        /***总消耗次数**/
        $total_recharge = ProxyRechargeLog::where("pid", $user_id)
            ->where("type", 3)
            ->where("status", 1)
            ->cache(true,600)
            ->sum("num");
        /**今日用户消耗**/
        $today_user_num = Db::table($bale_rate_table)
            ->where("pid", $user_id)
            ->where("status", 1)
            ->whereTime("create_time", "d")
            ->cache(true,600)
            ->count("id");
        /**用户总消耗***/
        $total_user_num = Db::table($bale_rate_table)
            ->where("pid", $user_id)
            ->where("status", 1)
            ->cache(true,600)
            ->count("id");
        $x = [];
        $y = [];
        /**今日***/
        if ($type == 1) {
            $hours = date("H");
            $date = date("Y-m-d");
            for ($i = 0; $i <= intval($hours); $i++) {
                $x[] = $h = date("H:i", strtotime($date . " " . $i . ":00"));
                $start_time = $date . " " . $h;
                $stop_time = date("Y-m-d H:00", strtotime("+1 hours", strtotime($start_time)));
                $y[] = Db::table($bale_rate_table)
                    ->where($list_where)
                    ->whereTime("create_time", "between", [$start_time, $stop_time])
                    ->cache(true,600)
                    ->count("id");
            }
        } else {
            for ($i = 30; $i >= 0; $i--) {
                $day = date("m-d", strtotime("-$i days"));
                $x[] = $day;
                $time = date("Y-m-d", strtotime("-$i days"));
                $y[] = Db::table($bale_rate_table)
                    ->where($list_where)
                    ->whereTime("create_time", "between", [$time . " 00:00", $time . " 23:59"])
                    ->cache(true,600)
                    ->count("id");
            }
        }
        $result = [
            "sign_num" => intval($user["sign_num"]),
            "today_recharge" => $today_recharge,
            "total_recharge" => $total_recharge,
            "today_user_num" => $today_user_num,
            "total_user_num" => $total_user_num,
            "list" => [
                "x" => $x,
                "y" => $y,
            ]
        ];
        $this->success('success', $result, 200);
    }

    /**
     * 次数统计(会员端)
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="type", type="Int", required=false, description="类型 1 今日 2累计")
     */
    public function user_statics()
    {
        $type = $this->request->param("type", 1);
        $app_id = $this->request->param("app_id", null);
        $user_id = $this->auth->id;
        $user = ProxyUser::get($user_id);
        if (empty($user)) {
            $this->error("数据错误");
        }
        $bale_rate_table = getTable("proxy_bale_rate", $user["pid"]);
        $list_where = [
            "pid" => $user["pid"],
            "user_id" => $user_id,
            "status" => 1
        ];
        if ($app_id) {
            $list_where["app_id"] = $app_id;
        }
        /**今日用户消耗**/
        $today_user_num = Db::table($bale_rate_table)
            ->where($list_where)
            ->whereTime("create_time", "d")
            ->count("id");
        /**用户总消耗***/
        $total_user_num = Db::table($bale_rate_table)
            ->where($list_where)
            ->count("id");
        $x = [];
        $y = [];
        /**今日***/
        if ($type == 1) {
            $hours = date("H");
            $date = date("Y-m-d");
            for ($i = 0; $i <= intval($hours); $i++) {
                $x[] = $h = date("H:i", strtotime($date . " " . $i . ":00"));
                $start_time = $date . " " . $h;
                $stop_time = date("Y-m-d H:00", strtotime("+1 hours", strtotime($start_time)));
                $y[] = Db::table($bale_rate_table)
                    ->where($list_where)
                    ->whereTime("create_time", "between", [$start_time, $stop_time])
                    ->count("id");
            }
        } else {
            for ($i = 30; $i >= 0; $i--) {
                $day = date("m-d", strtotime("-$i days"));
                $x[] = $day;
                $time = date("Y-m-d", strtotime("-$i days"));
                $y[] = Db::table($bale_rate_table)
                    ->where($list_where)
                    ->whereTime("create_time", "between", [$time . " 00:00", $time . " 23:59"])
                    ->count("id");
            }
        }
        $result = [
            "sign_num" => intval($user["sign_num"]),
            "today_user_num" => $today_user_num,
            "total_user_num" => $total_user_num,
            "list" => [
                "x" => $x,
                "y" => $y,
            ]
        ];
        $this->success('success', $result, 200);
    }

    /**
     * 防盗刷验证
     * @throws \think\exception\DbException
     */
    public function vaptcha()
    {
        $app_id = $this->request->post("app_id");
        $user_id = $this->auth->id;
        $app = ProxyApp::get(['id' => $app_id, 'user_id' => $user_id]);
        if (!$app) {
            $this->error(__('应用不存在'));
        }
        $update = [
            "id" => $app_id,
        ];
        if ($app["is_vaptcha"] == 1) {
            $update["is_vaptcha"] = 0;
        } else {
            $update["is_vaptcha"] = 1;
        }
        if (ProxyApp::update($update)) {
            $this->success('success', null, 200);
        } else {
            $this->error('fail');
        }
    }

    /**
     * 应用列表导出
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="keywords", type="String", required=true, description="关键字")
     */
    public function export_app_list()
    {
        $keywords = $this->request->param('keywords');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('app_list');
        $spreadsheet->getActiveSheet()->setTitle('Hello');
        //设置第一行小标题
        $k = 1;
        $sheet->setCellValue('A' . $k, 'ID');
        $sheet->setCellValue('B' . $k, '应用名称');
        $sheet->setCellValue('C' . $k, '版本号');
        $sheet->setCellValue('D' . $k, '安装地址');
        $sheet->setCellValue('E' . $k, '微信防封地址');
        $sheet->setCellValue('F' . $k, '备注');
        $sheet->setCellValue('G' . $k, '下载量');
        $sheet->setCellValue('H' . $k, '更新时间');
        $sheet->setCellValue('I' . $k, '上下架状态');

        $where = [
            'user_id' => $this->auth->id,
            'is_delete' => 1,
            'type' => 1,
        ];
        $keywords && $where['name|remark'] = ['like', '%' . $keywords . '%'];
        $user = ProxyUser::get($this->auth->id);
        $proxy = ProxyUserDomain::get(['user_id' => $user['pid']]);
        $wx_url = Config::where("name", "proxy_wx_url")
//            ->cache(true, 600)
            ->value("value");

        $list = ProxyApp::where($where)
            ->order('create_time', 'desc')
            ->column('id,name,version_code,tag,download_num,status,create_time,update_time,short_url,remark');

        if (empty($list)) {
            $this->error('暂无APP');
        }
        $bale_rate_table = getTable("proxy_bale_rate",$user["pid"]);
        foreach ($list as $k => $v) {
            if($proxy["ext"]=="app"){
                $list[$k]['url'] = 'https://' . $proxy['download_url'] . '/' . $v['tag'] . '.app';
            }else{
                $list[$k]['url'] = 'https://' . $proxy['download_url'] . '/' . $v['short_url'] . '.html';
            }
            if(!empty($proxy["wx1_host"])){
                $list[$k]["wx_url"] = 'https://' . $proxy['wx1_host']  . '/' . $v['short_url'] . '.html';
            }else{
                $list[$k]["wx_url"] = $wx_url . '/' . $v['short_url'] . '.html';
            }
            $list[$k]['download_num'] = Db::table($bale_rate_table)->where("app_id",$v["id"])->where("status",1)->count();
            $list[$k]['create_time'] = !empty($list[$k]['update_time']) ? $list[$k]['update_time'] : $list[$k]['create_time'];
            $list[$k]['status'] = $v['status'] ? '上架' : '下架';
        }

        $spreadsheet->getActiveSheet()->getColumnDimension('A')->setWidth(20);
        $k = 2;
        foreach ($list as $key => $value) {
            $sheet->setCellValue('A' . $k, $value['id']);
            $sheet->setCellValue('B' . $k, $value['name']);
            $sheet->setCellValue('C' . $k, $value['version_code']);
            $sheet->setCellValue('D' . $k, $value['url']);
            $sheet->setCellValue('E' . $k, $value['wx_url']);
            $sheet->setCellValue('F' . $k, $value['remark']);
            $sheet->setCellValue('G' . $k, $value['download_num']);
            $sheet->setCellValue('H' . $k, $value['create_time']);
            $sheet->setCellValue('I' . $k, $value['status']);
            $k++;
        }
        $file_name = date('Y-m-d', time()) . rand(1000, 9999);
        //第一种保存方式
        $writer = new Xlsx($spreadsheet);
        //保存的路径可自行设置
        $save_name = ROOT_PATH . '/runtime/upload/' . $file_name . ".xlsx";
        $writer->save($save_name);
        $save = "cache-uploads/xlsx/" . $file_name . ".xlsx";
        //上传到OSS服务器
        $sign = md5($save."uSl!I~vGjYQHJUXjTxUO");
        $client=curl_client('post',[
            'file'=>new \CURLFile($save_name),
            'sign'=>$sign,
            'key'=>$save
        ],'http://34.135.101.133:85/index/cache_upload');
        $client['code']=$client['code']??0;
        if($client&&$client['code']==200){
            @unlink($save_name);
            $ip2 = new Ip2Region();
            $ip_address = $ip2->binarySearch($this->request->ip());
            $address = explode('|', $ip_address['region']);
            if ($address[0] == "中国" && !in_array($address[2], ["澳门", '香港', "台湾省","台湾"])) {
                $xlsx_url = Config::where('name', 'proxy_zh_oss_public_url')->value('value');
            } else {
                $xlsx_url = Config::where('name', 'proxy_en_oss_public_url')->value('value');
            }
            $xlsx_url = $xlsx_url .'/'. $save;
        }else{
            $this->error(__('导出失败'));
        }
        //上传到OSS
        /*$oss_read_config = OssConfig::where("status",1)
            ->where("name","g_oss_read")
            ->find();
        $oss = new Oss($oss_read_config);
        if ($oss->ossUpload($save_name, $save)) {
            @unlink($save_name);
            $xlsx_url = $oss->oss_url() . $save;
        } else {
            $this->error(__('导出失败'));
        }*/
        $this->success('success', $xlsx_url, 200);
    }

    /***
     * 下载记录导出
     */
    public function export_pay_list(){
        $name = $this->request->post('name', null);
        $start = $this->request->post('start', null);
        $end = $this->request->post('end', null);
        $where['b.user_id'] = $this->auth->id;
        $name && $where['a.name'] = ['like', '%' . $name . '%'];
        $where['b.status'] = 1;
        if($start && $end){
            $time_diff = floor((strtotime($end)-strtotime($start))/86400);
            if($time_diff>31){
                $start = date("Y-m-d",strtotime("-1 months",strtotime($end)));
            }
        }else{
            $end = date("Y-m-d");
            $start =date("Y-m-d",strtotime("-1 months"));
        }
        $end = $end . ' 23:59:59';
        $where['b.create_time'] = ['between', [$start, $end]];
        $user = ProxyUser::where("id", $this->auth->id)->find();
        $bale_rate_table = getTable("proxy_bale_rate", $user["pid"]);

        $list = Db::table($bale_rate_table)->alias('b')
            ->join('proxy_app a', 'b.app_id = a.id')
            ->where($where)
            ->field('a.name,b.udid,b.create_time,b.status,b.ip,b.resign_udid')
            ->order('b.create_time', 'desc')
            ->select();

        /***excel**/
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('app_list');
        $spreadsheet->getActiveSheet()->setTitle('Hello');
        //设置第一行小标题
        $k = 1;
        $sheet->setCellValue('A' . $k, '应用名称');
        $sheet->setCellValue('B' . $k, '日期');
        $sheet->setCellValue('C' . $k, '设备号');
        $sheet->setCellValue('D' . $k, 'IP');

        $spreadsheet->getActiveSheet()->getColumnDimension('A')->setWidth(20);
        $k = 2;
        foreach ($list as $k => $v) {
            if ($v["resign_udid"]) {
                $v["udid"] = $v["resign_udid"];
            }
            $sheet->setCellValue('A' . $k, $v['name']);
            $sheet->setCellValue('B' . $k, $v['create_time']);
            $sheet->setCellValue('C' . $k, $v['udid']);
            $sheet->setCellValue('D' . $k, $v['ip']);
            $k++;
        }
        $file_name = date('Y-m-d', time()) . rand(1000, 9999);
        //第一种保存方式
        $writer = new Xlsx($spreadsheet);
        //保存的路径可自行设置
        $save_name = ROOT_PATH . '/runtime/upload/' . $file_name . ".xlsx";
        $writer->save($save_name);
        $save = "cache-uploads/xlsx/" . $file_name . ".xlsx";
        //上传到OSS服务器
        $sign = md5($save."uSl!I~vGjYQHJUXjTxUO");
        $client=curl_client('post',[
            'file'=>new \CURLFile($save_name),
            'sign'=>$sign,
            'key'=>$save
        ],'http://34.135.101.133:85/index/cache_upload');
        $client['code']=$client['code']??0;
        if($client&&$client['code']==200){
            @unlink($save_name);
            $ip2 = new Ip2Region();
            $ip_address = $ip2->binarySearch($this->request->ip());
            $address = explode('|', $ip_address['region']);
            if ($address[0] == "中国" && !in_array($address[2], ["澳门", '香港', "台湾省","台湾"])) {
                $xlsx_url = Config::where('name', 'proxy_zh_oss_public_url')->value('value');
            } else {
                $xlsx_url = Config::where('name', 'proxy_en_oss_public_url')->value('value');
            }
            $xlsx_url = $xlsx_url .'/'. $save;
        }else{
            $this->error(__('导出失败'));
        }
        //上传到OSS
        /*$oss_read_config = OssConfig::where("status",1)
            ->where("name","g_oss")
            ->find();
        $oss = new Oss($oss_read_config);
        if ($oss->ossUpload($save_name, $save)) {
            @unlink($save_name);
            $xlsx_url = $oss->signUrl($save);
        } else {
            $this->error(__('导出失败'));
        }*/
        $this->success('success', $xlsx_url, 200);
    }

    public function app_v1_pay_list()
    {
        $id = $this->request->post('id', 0);
        $name = $this->request->post('name', null);
        $start = $this->request->post('start', null);
        $end = $this->request->post('end', null);
        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 10);
        if (intval($page) < 1) $page = 1;
        $offset = ($page - 1) * $pageSize;
        $where['user_id'] = $this->auth->id;
        if(!empty($id)){
            $where['app_id'] = $id;
        }elseif(!empty($name)){
            $app_id = ProxyApp::where("user_id",$this->auth->id)
                ->where("status",1)
                ->where("is_delete",1)
                ->where("is_download",0)
                ->where("name",'like', '%' . $name . '%')
                ->column("id");
            $where['app_id'] = ["IN",$app_id];
        }else{
            $app_id = ProxyApp::where("user_id",$this->auth->id)
                ->where("status",1)
                ->where("is_delete",1)
                ->where("is_download",0)
                ->column("id");
            $where['app_id'] = ["IN",$app_id];
        }
        $where['status'] = 1;
        $end && $end = $end . ' 23:59:59';
        $start && $end && $where['create_time'] = ['between', [$start, $end]];
        $user = ProxyUser::where("id", $this->auth->id)->find();
        $bale_rate_table = getTable("proxy_v1_bale_rate", $user["pid"]);
        $proxy_views_table = getTable("proxy_app_views", $user["pid"], 100);
        $total = Db::table($bale_rate_table)
            ->connect("v1_ios")
            ->where($where)
            ->count();
        $list = Db::table($bale_rate_table)
            ->connect("v1_ios")
            ->where($where)
            ->field('app_id,udid,create_time,status,ip,resign_udid,device')
            ->order('create_time', 'desc')
            ->limit($offset, $pageSize)
            ->select();
        foreach ($list as $k => $v) {
            if ($v["resign_udid"]) {
                $list[$k]["udid"] = $v["resign_udid"];
            }
            $list[$k]["money"] = 1;
            $app_name = ProxyApp::where("id",$v["app_id"])
                ->cache(true,300)
                ->value("name");
            $list[$k]["name"] = $app_name;
//            if (empty($v['ip'])) {
//                $v['ip'] = Db::table($proxy_views_table)->where(['app_id' => $v['app_id'], 'udid' => $v['udid']])->cache(true, 1800)->value('ip');
//            }
        }
        $result = [
            'total' => $total,
            'currentPage' => $page,
            'list' => array_values($list)
        ];
        $this->result('success', $result, 200);
    }


}