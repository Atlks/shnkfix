<?php

namespace app\api\controller;

use app\api\library\OtherLogin;
use app\common\controller\Api;
use app\common\library\Sms;
use app\common\library\Auth;
use app\common\model\ProxyUserMoneyLogV1;
use think\Validate;
use fast\Random;
use think\captcha\Captcha;
use app\common\model\ProxyUser;
use app\common\model\ProxyDownload;
use app\common\model\ProxyBaleRate;
use app\common\model\ProxyRechargeLog;
use app\common\model\ProxyDomain;
use app\common\model\ProxyResignFree as Resign;
use app\common\model\ProxyVerified;
use app\common\library\Oss;
use app\common\library\IdCardOrc;
use think\Db;
use app\common\library\WyDun;


/**
 * 会员接口
 */
class User extends Api
{
    protected $noNeedLogin = ['login', 'mobilelogin', 'register', 'resetpwd', 'changeemail', 'changemobile', 'third', 'otherLogin'];
    protected $noNeedRight = '*';

    public function _initialize()
    {
        parent::_initialize();
    }

    /**
     * 会员中心
     * @ApiHeaders  (name=token, type=string, required=true, description="请求的Token")
     * @ApiReturnParams   (name="sign_num", type="integer", required=true, description="剩余次数")
     * @ApiReturnParams   (name="is_proxy", type="integer", required=true, description="是否代理1-时0-否")
     * @ApiReturnParams   (name="download_count", type="integer", required=true, description="下载总数")
     * @ApiReturnParams   (name="equipment_count", type="integer", required=true, description="设备总数")
     * @ApiReturnParams   (name="member_count", type="integer", required=true, description="会员数")
     * @ApiReturnParams   (name="resign_count", type="integer", required=true, description="重签数")
     */
    public function index()
    {
        $user = ProxyUser::where('id' , $this->auth->id)
            ->field('id,username,nickname,mobile,sign_num,private_num,authentication,is_proxy,v1_num')
            ->find();
        if ($user["is_proxy"] == 1) {
            $where = ['pid' => $user['id']];
            $bale_rate_table = getTable("proxy_bale_rate", $user["id"]);
            $userList = ProxyUser::where($where)->where('status', 'normal')->column('id');
            $recharge_log_where = ['user_id' => $this->auth->id, 'type' => 1, 'status' => 1];
        } else {
            $userList = [$user['id']];
            $where = ['user_id' => $user['id']];
            $bale_rate_table = getTable("proxy_bale_rate", $user["id"]);
            $recharge_log_where = ['user_id' => $this->auth->id, 'type' => ["in",[1,5]], 'status' => 1];
        }
        $user['download_count'] = Db::table($bale_rate_table)->where($where)
            ->where('status', 1)
            ->cache(true, 1800)
            ->count();//总下载
        $user["equipment_count"] = Db::table($bale_rate_table)->where($where)
            ->where('status', 1)
            ->group("udid")
            ->cache(true, 1800)
            ->count();//总下载
//        $user['equipment_count'] =  Db::table($download_table)->where('user_id','IN',$userList)
//            ->cache(true,1800)
//            ->count();//总设备
//        $user['member_count'] =  ProxyUser::where($where)->where('status','normal')->count();//会员数
        $user['member_count'] = count($userList);//会员数
        //重签数
//        $user['resign_count'] =  Resign::where($where)
//            ->cache(true,1800)
//            ->count();
        //累计充次
        $user['total_num'] = ProxyRechargeLog::where($recharge_log_where)->sum('num');
        $user["sign_num"] = intval($user["sign_num"]);
        $user["v1_num"] = intval($user["v1_num"]);
        $this->success('ok', $user, 200);
    }

    /**
     * 会员登录
     *
     * @param string $account 账号
     * @param string $password 密码
     * @param string $captcha 验证码
     * @param string $code 验证码id
     * @param string $domain 当前域名
     * @param string $type 参数1-代理0-用户
     */
    public function login()
    {
        $domain = $this->request->request('domain');
        $account = $this->request->request('account');
        $password = $this->request->request('password');
        $captcha = $this->request->request('captcha');
        $code = $this->request->request('code');
        $type = $this->request->request('type');
        $proxy = ProxyDomain::where('find_in_set(:domain,domain)',['domain'=>$domain])->find();
        if ($type != 1 && empty($proxy)) {
            $this->error(__('代理不存在'));
        }
        if (!$account || !$password || !$captcha) {
            $this->error("请输入正确账号");
        }
        /*网易易盾*/
        $result = (new WyDun())->verify($captcha, '');
        if ($result !== true) {
            $this->error(__('验证码错误'));
        }
        /*本机验证*/
        /* $ca = new Captcha();
         if(!in_array($account,['admin','test'])){
             if(!$ca->checkApi($captcha,$code)){
                 $this->error(__('验证码错误'));
             }
         }*/
        /***账号限制IP**/
        if(trim($account)=="weiwei5858"){
            $ip = $this->ip();
            if($ip!=="14.128.63.70"||$ip!="34.87.107.230"){
                $this->error("无权限登录");
            }
        }
        $ret = $this->auth->login($account, $password, $proxy['user_id']);
        if ($ret) {
            $data = $this->auth->getUserinfo();
            if ($data['is_proxy'] != $type) {
                $this->error(__('账号与客户端不符合'));
            }

            if ($type != 1 && $data['pid'] != $proxy['user_id']) {
                //用户
                $this->error(__('账号与代理不匹配'));
            }

            $this->success(__('Logged in successful'), $data, 200);
        } else {
            $this->error($this->auth->getError());
        }
    }

    /**
     * 手机验证码登录
     * @param string $mobile 手机号
     * @param string $captcha 验证码
     * @param string $domain 当前域名
     * @param string $type 参数1-代理0-用户
     */
    public function mobilelogin()
    {
        $domain = $this->request->request('domain');
        $mobile = $this->request->request('mobile');
        $captcha = $this->request->request('captcha');
        $type = $this->request->request('type');
        $proxy = ProxyDomain::where('find_in_set(:domain,domain)',['domain'=>$domain])->find();
        if ($type != 1 && empty($proxy)) {
            $this->error(__('代理不存在'));
        }
        if (!$mobile || !$captcha) {
            $this->error(__('Invalid parameters'));
        }
        if (!Validate::regex($mobile, "^1\d{10}$")) {
            $this->error(__('Mobile is incorrect'));
        }
        if (!Sms::check($mobile, $captcha, 'mobilelogin')) {
            $this->error(__('Captcha is incorrect'));
        }
        $user = ProxyUser::getByMobile($mobile);
        if ($user) {
            if ($user->status != 'normal') {
                $this->error(__('Account is locked'));
            }
            if ($user['is_proxy'] != $type) {
                $this->error(__('账号与客户端不符合'));
            }
            if ($type != 1 && $user['pid'] != $proxy['user_id']) {
                $this->error(__('账号与代理不匹配'));
            }
            //如果已经有账号则直接登录
            $ret = $this->auth->direct($user->id);
        } else {
            $this->error(__('手机号未注册'));
            //  $ret = $this->auth->register($mobile, Random::alnum(), '', $mobile, []);
        }

        if ($ret) {
            Sms::flush($mobile, 'mobilelogin');
            $data = ['userinfo' => $this->auth->getUserinfo()];
            $this->success(__('Logged in successful'), $data);
        } else {
            $this->error($this->auth->getError());
        }
    }

    /**
     * 注册会员
     *
     * @param string $password 密码
     * @param string $captcha 验证码
     * @param string $account 账号
     * @param string $domain 域名
     * @param string $code 验证码ID
     */
    public function register()
    {
        $domain = $this->request->request('domain');
        $account = $this->request->request('account');
        $password = $this->request->request('password');
        $captcha = $this->request->request('captcha');
        $code = $this->request->request('code');
        $proxy = ProxyDomain::where('find_in_set(:domain,domain)',['domain'=>$domain])->find();
        if (empty($proxy)) {
            $this->error(__('注册失败'));
        }
        $rule = [
            'account' => 'require|length:2,16',
//            'captcha'    => 'require',
//            'code'       => 'require',
            'password' => 'require|length:6,20',
        ];
        $msg = [
            'account.require' => '用户名不能为空',
            'account.length' => '请输入2-16位用户名',
            'password.require' => '请输入密码',
            'password.length' => '密码必须是6至20位字母或数字组合',
//            'captcha.require'  => 'Captcha can not be empty',
//            'code.require'      => '注册失败',
        ];

        $data = [
            'account' => $account,
            'password' => $password,
//            'captcha'   => $captcha,
//            'code'   => $code,
        ];
        $validate = new Validate($rule, $msg);
        $result = $validate->check($data);
        if (!$result) {
            $this->error(__($validate->getError()));
        }
        if (!$account || !$password || !$captcha) {
            $this->error("请输入正确账号");
        }
        /*易盾验证*/
        $wyresult = (new WyDun())->verify($captcha, '');
        if ($wyresult !== true) {
            $this->error(__('验证码错误'));
        }
        /*本机验证*/
        /*$ca = new Captcha();
        if(!$ca->checkApi($captcha,$code)){
            $this->error(__('验证码错误'));
        }*/
        $extend = [
            'rate' => 0,
            'sign_num' => 0,
        ];
        $mobile = '';
        if (Validate::regex($account, "^1\d{10}$")) {
            $mobile = $account;
        }
        $ret = $this->auth->register($account, $password, $proxy['user_id'], '', $mobile, $extend);
        if ($ret) {
            $data = ['userinfo' => $this->auth->getUserinfo()];
            $this->success(__('Sign up successful'), $data, 200);
        } else {
            $this->error($this->auth->getError());
        }
    }

    /**
     * 注销登录
     */
    public function logout()
    {
        $this->auth->logout();
        $this->success(__('Logout successful'));
    }

    /**
     * 修改密码
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="oldpwd", type="String", required=true, description="旧密码")
     * @ApiParams   (name="pwd", type="String", required=true, description="新密码")
     * @ApiParams   (name="repwd", type="String", required=true, description="确认密码")
     */
    public function changePwd()
    {
        $oldpwd = $this->request->request("oldpwd");
        $pwd = $this->request->request("pwd");
        $repwd = $this->request->request("repwd");
        $rule = [
            'oldpwd' => 'require',
            'pwd' => 'require|length:6,20',
            'repwd' => 'require|confirm:pwd',
        ];
        $msg = [
            'oldpwd.require' => '请输入旧密码',
            'pwd.require' => 'Password can not be empty',
            'pwd.length' => 'Password must be 6 to 20 characters',
            'repwd.require' => '确认密码不能为空',
            'repwd.confirm' => '两次密码不一致',
        ];

        $data = [
            'oldpwd' => $oldpwd,
            'pwd' => $pwd,
            'repwd' => $repwd,
        ];
        $validate = new Validate($rule, $msg);
        $result = $validate->check($data);
        if (!$result) {
            $this->error(__($validate->getError()));
        }
        $user = ProxyUser::get($this->auth->id);
        if (!$user) {
            $this->error(__('User not found'));
        }

        //模拟一次登录
        $this->auth->direct($user->id);
        $ret = $this->auth->changepwd($pwd, $oldpwd);
        if ($ret) {
            $this->success(__('Reset password successful'), [], 200);
        } else {
            $this->error($this->auth->getError());
        }
    }

    /**
     * 重置密码
     *
     * @param string $mobile 手机号
     * @param string $password 新密码
     * @param string $repassword 新密码
     * @param string $captcha 验证码
     */
    public function resetpwd()
    {
        $mobile = $this->request->request("mobile");
        $password = $this->request->request("password");
        $repassword = $this->request->request("repassword");
        $captcha = $this->request->request("captcha");

        $rule = [
            'mobile' => 'require|regex:/^1\d{10}$/',
            'captcha' => 'require',
            'password' => 'require|length:6,20',
            'repassword' => 'require|confirm:password',
        ];
        $msg = [
            'mobile.require' => '手机号码不能为空',
            'mobile.regex' => 'Mobile is incorrect',
            'password.require' => 'Password can not be empty',
            'password.length' => 'Password must be 6 to 20 characters',
            'repassword.require' => '确认密码不能为空',
            'repassword.confirm' => '两次密码不一致',
            'captcha.require' => 'Captcha can not be empty',

        ];

        $data = [
            'mobile' => $mobile,
            'password' => $password,
            'captcha' => $captcha,
            'repassword' => $repassword,
        ];
        $validate = new Validate($rule, $msg);
        $result = $validate->check($data);
        if (!$result) {
            $this->error(__($validate->getError()));
        }
        $user = \app\common\model\ProxyUser::getByMobile($mobile);
        if (!$user) {
            $this->error(__('User not found'));
        }

        if (!Sms::check($mobile, $captcha, 'resetpwd')) {
            $this->error(__('Captcha is incorrect'));
        }

        Sms::flush($mobile, 'resetpwd');
        //模拟一次登录
        $this->auth->direct($user->id);
        $ret = $this->auth->changepwd($password, '', true);
        if ($ret) {
            $this->success(__('Reset password successful'), [], 200);
        } else {
            $this->error($this->auth->getError());
        }
    }

    /**
     * 代理会员列表
     * @ApiMethod   (POST)
     * @ApiHeaders  (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams      (name="mobile", type="string", required=false, description="手机号码/用戶名")
     * @ApiParams      (name="page", type="integer", required=true, description="当前页")
     * @ApiParams      (name="page_size", type="integer", required=true, description="每页显示数")
     * @ApiReturnParams   (name="rate", type="Int", required=true, description="费率")
     * @ApiReturnParams   (name="sign_num", type="Int", required=true, description="剩余次数")
     * @ApiReturnParams   (name="used", type="Int", required=true, description="已使用次数")
     * @ApiReturnParams   (name="status", type="String", required=true, description="状态normal-正常hidden-删除")
     */
    public function user_list()
    {
        $pid = $this->auth->id;
        $keywords = $this->request->post('mobile');
        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 10);
        $orderField = $this->request->param('order_field', "createtime");
        $orderType = $this->request->param('order_type', "DESC");
        if (intval($page) < 1) $page = 1;
        $offset = ($page - 1) * $pageSize;
        if (!$pid) {
            $this->error('参数错误');
        }
        if ($orderField == "used"||$orderField=="today_download") {
            $order_all_filed = $orderField;
            $orderField = "createtime";
            $is_used = true;
        } else {
            $is_used = false;
        }
        $table = getTable("proxy_bale_rate", $pid);
        $where['pid'] = $pid;
        $where['status'] = 'normal';
        $keywords && $where['mobile|username'] = ['like', '%' . $keywords . '%'];

        $total = ProxyUser::where($where)->count();
        if ($is_used) {
            $list = ProxyUser::where($where)
                ->order($orderField, $orderType)
                ->column('id,username,nickname,mobile,rate,sign_num,logintime,jointime,status');
        } else {
            $list = ProxyUser::where($where)
                ->order($orderField, $orderType)
                ->limit($offset, $pageSize)
                ->column('id,username,nickname,mobile,rate,sign_num,logintime,jointime,status');
        }
        foreach ($list as &$v) {
            $v["sign_num"] = intval($v["sign_num"]);
            $v['logintime'] = date('Y-m-d H:i:s', $v['logintime']);
            $v['jointime'] = date('Y-m-d H:i:s', $v['jointime']);
            $v['used'] = Db::table($table)->where(['user_id' => $v['id'], 'pid' => $pid, 'status' => 1])
                ->cache(true, 300)
                ->count();
            $v['today_download'] = Db::table($table)
                ->where(['user_id' => $v['id'], 'pid' => $pid, 'status' => 1])
                ->whereTime("create_time","d")
                ->cache(true, 300)
                ->count();

        }
        if ($is_used) {
            if (strtoupper($orderType) == "ASC") {
                array_multisort(array_column($list, $order_all_filed), SORT_ASC, $list);
            } else {
                array_multisort(array_column($list, $order_all_filed), SORT_DESC, $list);
            }
            $list = array_slice($list, $offset, $pageSize);
        }
        $result = [
            'total' => $total,
            'currentPage' => $page,
            'list' => array_values($list)
        ];
        $this->result('success', $result, 200);
    }

    /**
     * 会员详情
     * @ApiMethod  (GET)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="Int", required=true, description="会员id")
     * @return array
     */
    public function userInfo()
    {
        $id = $this->request->param('id');
        if (!$id) {
            $this->error(__('Invalid parameters'));
        }
        $info = ProxyUser::where(['id' => $id, 'pid' => $this->auth->id, 'status' => 'normal'])
            ->field('id,username,nickname,mobile,sign_num,rate,is_proxy,pid')
            ->find();

        if (!$info) {
            $this->error('会员不存在');
        }
        $bale_rate_table = getTable("proxy_bale_rate", $info["pid"]);
        $download_table = getTable("proxy_download", $info["pid"]);
        $where = ['user_id' => $id];
        $info['download_count'] = $info['equipment_count'] = Db::table($bale_rate_table)->where($where)
            ->where('status', 1)
//            ->cache(true,1800)
            ->count();//总下载
//        $info['equipment_count'] =  Db::table($download_table)->where($where)
////            ->cache(true,1800)
//            ->count();//总设备
//        $info['resign_count'] = Resign::where($where)
////            ->cache(true,1800)
//            ->count();//重签数
        $info['resign_count'] = 0;
        $info['sign_num'] = intval($info["sign_num"]);
//        $rtotal = Resign::where($where)->whereTime('create_time','<','2019-11-10 00:00:00')->count('id');//补签
//        $coach_rtotal = Resign::where($where)->whereTime('create_time','>=','2019-11-10 00:00:00')->count('id');//补签
//        /***向下取整**/
//        $info['resign_count'] = $rtotal+floor($coach_rtotal*0.3);//补签
//        $info['equipment_count'] =  $info['equipment_count']+($coach_rtotal-floor($coach_rtotal*0.3));

        $this->result('success', $info, 200);
    }

    /**
     * 新增会员
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="username", type="String", required=true, description="用户名")
     * @ApiParams   (name="nickname", type="String", required=true, description="昵称")
     * @ApiParams   (name="mobile", type="String", required=false, description="手机")
     * @ApiParams   (name="password", type="String", required=true, description="密码")
     * @ApiParams   (name="rate", type="Int", required=true, description="费率")
     * @ApiParams   (name="sign_num", type="Int", required=true, description="次数")
     */
    public function userAdd()
    {
        $username = $this->request->post('username');
        $nickname = $this->request->post('nickname');
        $mobile = $this->request->post('mobile');
        $password = $this->request->post('password');
        $rate = $this->request->post('rate');
        $sign_num = $this->request->post('sign_num');
        if (empty($username)) {
            $this->error(__('用户名不能为空'));
        }
        if ($mobile && !Validate::regex($mobile, "^1\d{10}$")) {
            $this->error(__('Mobile is incorrect'));
        }
        if (empty($password)) {
            $this->error(__('密码不能为空'));
        }
        $user = ProxyUser::getByUsername($username);
        if ($user) {
            $this->error(__('用户名已存在'));
        } else {
            $extend = [
                'nickname' => $nickname,
                'rate' => $rate,
                'sign_num' => $sign_num,
            ];
            //检测剩余次数是否足够
            if ($sign_num > $this->auth->sign_num) {
                $this->error(__('当前剩余次数不足'));
            }
            $ret = $this->auth->proxy_add($username, $password, $this->auth->id, $mobile, $extend);
        }

        if ($ret) {
            $this->success(__('添加成功'), [], 200);
        } else {
            $this->error($this->auth->getError());
        }
    }

    /**
     * 会员操作
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="id", type="array", required=true, description="会员id")
     * @ApiParams   (name="type", type="Int", required=true, description="操作类型;1-删除;2-修改")
     * @ApiParams   (name="num_type", type="Int", required=false, description="次数操作类型;1-增加;2-减少")
     * @ApiParams   (name="num", type="Int", required=false, description="次数")
     * @ApiParams   (name="password", type="String", required=false, description="密码")
     * @ApiParams   (name="nickname", type="String", required=false, description="昵称")
     * @ApiParams   (name="mobile", type="String", required=false, description="手机")
     * @ApiParams   (name="rate", type="Int", required=false, description="费率")
     */
    public function userHandle()
    {

        $type = $this->request->post('type');
        $type = $type ?? 1;//默认删除
        switch ($type) {
            case 1;
                $ids = $this->request->post('id/a');
                if (empty($ids) || !is_array($ids)) {
                    $this->error(__('Invalid parameters'));
                }

                foreach ($ids as $id) {
                    $user = ProxyUser::get(['id' => $id, 'pid' => $this->auth->id]);
                    if (empty($user)) {
                        $this->error(__('用户不存在'));
                    }
                    $update['status'] = 'hidden';
                    try {
                        Proxyuser::where(['id' => $id, 'pid' => $this->auth->id])->update($update);

                    } catch (\Exception $e) {
                        $this->error('操作失败');
                    }
                }

                break;
            case 2;
                $id = $this->request->post('id');
                $password = $this->request->post('password');
                $nickname = $this->request->post('nickname');
                $mobile = $this->request->post('mobile');
                $rate = $this->request->post('rate');
                $num_type = $this->request->post('num_type');
                $num = $this->request->post('num');
                if (empty($id)) {
                    $this->error(__('Invalid parameters'));
                }
                if ($num < 0) {
                    $this->error(__('请输入正确次数'));
                }

                $user = ProxyUser::get(['id' => $id, 'pid' => $this->auth->id]);

                if (empty($user)) {
                    $this->error(__('用户不存在'));
                }
                //1-增加;2-减少

                if (!empty($num_type) && !in_array($num_type, [1, 2])) {
                    $this->error(__('请选择次数操作类型'));
                }
                $update = [];
                if ($num != 0) {
                    if ($num_type == 1) {
                        //是否大于代理次数
                        if ($num > $this->auth->sign_num) {
                            $this->error(__('次数输入错误'));
                        }
                        $sing_num = bcadd($num, $user['sign_num']);
                        $proxy_sing_num = bcsub($this->auth->sign_num, $num);
                        $log_type_m = 5;//会员----冲次
                        $log_type_p = 3;//代理----会员冲次
                    } else {
                        /**2352ID代理线不限次扣除**/
                        if($this->auth->id==2352||$this->auth->id==6354||$this->auth->id==4204){
                            //是否大于会员次数
                            if ($num > $user['sign_num']) {
                                $this->error(__('次数输入错误'));
                            }
                        }else{
                            //48小时内冲次
                            $add_num = ProxyRechargeLog::where(['user_id' => $id, 'type' => 5])->whereTime('create_time', '>=', '-48 hour')->sum('num');
                            //48小时扣除
                            $sub_num = ProxyRechargeLog::where(['user_id' => $id, 'type' => 6])->whereTime('create_time', '>=', '-48 hour')->sum('num');
                            //代理可扣次数
                            $proxy_sub_num = bcsub($add_num, $sub_num);
                            if ($proxy_sub_num <= 0) {
                                $this->error(__('当前暂无可扣次数'));
                            }
                            if ($num > $proxy_sub_num) {
                                $this->error(__('次数输入错误,您当前可扣次数为:' . $proxy_sub_num . '次'));
                            }
                            //是否大于会员次数
                            if ($num > $user['sign_num']) {
                                $this->error(__('次数输入错误'));
                            }
                        }
                        $sing_num = bcsub($user['sign_num'], $num);
                        $proxy_sing_num = bcadd($this->auth->sign_num, $num);
                        $log_type_m = 6;//会员----退回
                        $log_type_p = 4;//代理----会员退回
                    }
                    $update['sign_num'] = $sing_num;
                }

                if (!empty($password)) {
                    $salt = Random::alnum();
                    $password = (new Auth())->getEncryptPassword($password, $salt);
                    $update['password'] = $password;
                    $update['salt'] = $salt;
                }
                if (!empty($mobile)) {
                    if (!Validate::regex($mobile, "^1\d{10}$")) {
                        $this->error(__('手机号码格式错误'));
                    }
                    if (ProxyUser::where('mobile', $mobile)->where('id', '<>', $id)->find()) {
                        $this->error(__('手机号已经存在'));
                    }
                    $update['mobile'] = $mobile;
                }
                $nickname && $update['nickname'] = $nickname;
                $rate && $update['rate'] = $rate;


                Db::startTrans();
                try {
                    if ($num != 0) {
                        $p_user = ProxyUser::get(["id" => $this->auth->id]);
                        $money_log_data = [
                            'user_id' => $user['id'],
                            'num' => $num,
                            'before' => $user['sign_num'],
                            'after' => $sing_num,
                            'memo' => "代理操作",
                            'createtime' => time(),
                            'money' => 0,
                            'own' => "",
                            'univalent' => 0,
                        ];
                        $p_money_log_data = [
                            'user_id' => $this->auth->id,
                            'num' => $num,
                            'before' => $p_user['sign_num'],
                            'after' => $proxy_sing_num,
                            'memo' => "代理操作",
                            'createtime' => time(),
                            'money' => 0,
                            'own' => "",
                            'univalent' => 0,
                        ];
                        Db::table('proxy_user_money_log')->insert($money_log_data);
                        Db::table('proxy_user_money_log')->insert($p_money_log_data);
                        recharge_log($this->auth->id, $id, $num, $log_type_m);//会员记录
                        recharge_log($this->auth->id, $id, $num, $log_type_p);//代理记录
                        Db::table("proxy_user")->where("id",$this->auth->id)->update(['sign_num' => $proxy_sing_num]);
//                        Proxyuser::where(['id' => $this->auth->id])->update(['sign_num' => $proxy_sing_num]);//用户修改次数
                    }
                    Db::table("proxy_user")->where(['id' => $id, 'pid' => $this->auth->id])->update($update);
//                    Proxyuser::where(['id' => $id, 'pid' => $this->auth->id])->update($update);
                    Db::commit();
                } catch (\Exception $e) {
                    Db::rollback();
                    $this->error('操作失败');
                }
                break;
            default;
                $this->error(__('Invalid parameters'));
                break;
        }

        $this->result('操作成功', [], 200);
    }

    /**
     * 冲次记录
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="user_id", type="Int", required=false, description="用户id")
     * @ApiParams   (name="type", type="Int", required=false, description="类型")
     * @ApiParams   (name="start", type="String", required=false, description="开始时间 Y-m-d")
     * @ApiParams   (name="end", type="String", required=false, description="结束时间")
     * @ApiParams      (name="page", type="integer", required=true, description="当前页")
     * @ApiParams      (name="page_size", type="integer", required=true, description="每页显示数")
     * @ApiReturnParams   (name="type", type="Int", required=true, description="类型;1-平台冲入2-平台扣除3-会员冲次4-会员退回5-冲次6-扣除7-消费")
     */
    public function rechargeLog()
    {
        $user_id = $this->request->post('user_id');
        $start = $this->request->post('start');
        $end = $this->request->post('end');
        $type = $this->request->post('type');
        $user_id = $user_id ? $user_id : $this->auth->id;
        $user = ProxyUser::get($user_id);
        if (empty($user)) {
            $this->error('用户不存在');
        }
        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 10);
        if (intval($page) < 1) $page = 1;
        $offset = ($page - 1) * $pageSize;
        $end && $end = $end . ' 23:59:59';
        $start && $end && $where['create_time'] = ['between', [$start, $end]];
        if ($user['is_proxy'] == 1) {
            $bale_rate_table = getTable("proxy_bale_rate", $user_id);
            $where_a['pid'] = $this->auth->id;//代理
            $where_c['type'] = 1;//代理冲次
            $where_b['type'] = 2;
            $where['type'] = ['in', [1, 2, 3, 4]];//1-平台冲入2-平台扣除3-会员冲次4-会员退回
        } else {
            $bale_rate_table = getTable("proxy_bale_rate", $user["pid"]);
            $where_a['user_id'] = $user_id;//会员
            $where_c['type'] = ["in",[1,5]];//会员冲次
            $where_b['type'] = ["in",[6]];
            $where['type'] = ['in', [1,5, 6]];//5-冲次6-扣除7-已使用
        }
        $type && $where['type'] = $type;
        $status = ['status' => 1];
        $total = ProxyRechargeLog::where($where)->where($status)->where($where_a)->count();
        $list = ProxyRechargeLog::where($where)
            ->where($status)
            ->where($where_a)
            ->order('create_time', 'desc')
            ->limit($offset, $pageSize)
            ->column('id,create_time,user_id,num,type,remark');
        //非代理 统计消费次数
        if ($user['is_proxy'] == 0) {
            $memberused = ProxyBaleRate::where($status)
                ->where($where_a)
                ->limit($offset, $pageSize)
                ->cache(true, 1800)
                ->column('id,create_time,user_id');
            if (!empty($memberused)) {
                foreach ($memberused as &$vo) {
                    $vo['num'] = 1;
                    $vo['type'] = 7;
                    $vo['remark'] = '';
                    $vo['user'] = ProxyUser::where(['id' => $vo['user_id']])->value('username');
                }
                $list = array_merge($list, $memberused);
            }
        }

        //已使用
        $num = Db::table($bale_rate_table)->where($status)
            ->where($where_a)
            ->cache(true, 1800)
            ->count();
        //当前剩余
        $sign_num = ProxyUser::where(['id' => $user_id])->value('sign_num');
        //累计冲次
        $cumulative = ProxyRechargeLog::where($where_c)
            ->where($status)
            ->where($where_a)
            ->sum('num');
        //累计退回
        $back = ProxyRechargeLog::where($where_b)
            ->where($status)
            ->where($where_a)
            ->sum('num');
        if (!empty($list)) {
            foreach ($list as &$v) {
                if ($v['user_id'] != 0) {
                    $v['user'] = ProxyUser::where(['id' => $v['user_id']])->value('username');
                } else {
                    $v['user'] = null;
                }
                //冲次扣除转换为负数
                if (in_array($v['type'], [2, 3, 6, 7])) {
                    $v['num'] = -1 * $v['num'];
                }
            }
        }
        $result = [
            'total' => $total,
            'currentPage' => $page,
            'list' => array_values($list),
            'used' => $num,
            'surplus' => intval($sign_num),
            'cumulative' => $cumulative,
            'back' => $back,
        ];

        $this->result('success', $result, 200);
    }

    /**
     * 第三方登录
     *
     * @param string $account 账号
     * @param string $password 密码
     * @param string $captcha 验证码
     * @param string $code 验证码id
     * @param string $domain 当前域名
     * @param string $type 参数1-代理0-用户
     */
    public function otherLogin()
    {
        $domain = $this->request->request('domain');
        $account = $this->request->request('account');
        $password = $this->request->request('password');
        $captcha = $this->request->request('captcha');
        $code = $this->request->request('code');
        $type = $this->request->request('type');
        $proxy = ProxyDomain::where('find_in_set(:domain,domain)',['domain'=>$domain])->find();
        if ($type != 1 && empty($proxy)) {
            $this->error(__('代理不存在'));
        }
        if (!$account || !$password || !$captcha || !$code) {
            $this->error(__('Invalid parameters'));
        }
        $ca = new Captcha();

        if (!in_array($account, ['admin', 'test'])) {
            if (!$ca->checkApi($captcha, $code)) {
                $this->error(__('验证码错误'));
            }
        }
        if ($proxy['is_other_login'] == 0) {
            $this->error('登录错误');
        }
        $otherLogin = new OtherLogin();
        $method = $proxy['login'] . 'Login';
        if (method_exists($otherLogin, $method)) {
            $class = new \ReflectionMethod('app\api\library\OtherLogin', $method);
            $result = $class->invokeArgs($otherLogin, [$account, $password]);
            if ($result['code'] != 200) {
                $this->error($result['msg']);
            }
            $time = time();
            $ip = $this->request->ip();
            $data = [
                'username' => $account,
                'level' => 1,
                'score' => 0,
                'avatar' => '',
                'is_proxy' => 0,//普通用户
                'pid' => $proxy['user_id'],//代理ID
                'salt' => Random::alnum(),
                'jointime' => $time,
                'joinip' => $ip,
                'logintime' => $time,
                'loginip' => $ip,
                'prevtime' => $time,
                'status' => 'normal'
            ];
            unset($result['code']);
            $data = array_merge($data, $result);
            $data['password'] = $this->auth->getEncryptPassword($password, $data['salt']);
            $user = ProxyUser::where(['username' => $account, 'pid' => $proxy['user_id']])->value('id');
            if ($user) {
                $this->auth->direct($user);
            } else {
                $user = ProxyUser::create($data, true);
                $this->auth->direct($user->id);
            }
            $data = $this->auth->getUserinfo();
            $this->success(__('Logged in successful'), $data, 200);
        } else {
            $this->error('无法登录！请联系网站管理员');
        }
    }

    /**
     *  实名认证验证
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="type", type="Int", required=true, description="1-上下架2-实名认证")
     * @ApiReturnParams (name="code", type="int", required=true, description="返回码200已认证0未认证1资料审核中2审核未通过")
     * @ApiReturnParams (name="name", type="String", required=true, description="真实姓名/企业名称")
     * @ApiReturnParams (name="num", type="String", required=true, description="证件号码")
     * @ApiReturnParams (name="region", type="String", required=true, description="所在地区")
     */
    public function checkAuthEntication()
    {
        $type = $this->request->param('type', 1);
        $user = ProxyUser::get($this->auth->id);
        $this->success('success', [], 200);
        $log = ProxyVerified::where(['user_id' => $user->id, 'status' => 0])->count();
        if ($user['authentication'] == 0) {
            if ($type == 1) {
                if (time() < strtotime(date('Y-12-20 00:00:00'))) {
                    $this->success('success', null, 3);
                }
            }
            if ($log > 0) {
                $this->error('认证资料审核中，请耐心等待', null, 1);
            } else {
                $this->success('未实名认证，请前往个人中心认证', null, 0);
            }

        } elseif ($user['authentication'] == 1) {
            if ($log > 0) {
                $this->error('认证资料审核中，请耐心等待', null, 1);
            }
            $logd = ProxyVerified::where(['user_id' => $user->id, 'status' => 1])
                ->field('name,num,region')
                ->order('createtime desc')
                ->find();
            $this->success('success', $logd, 200);
        } else {
            $this->error('审核未通过', null, 2);
        }
    }

    /**
     *  实名认证
     * @ApiMethod (POST)
     * @ApiHeaders    (name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="identityfront", type="String", required=true, description="身份证正面")
     * @ApiParams   (name="identityback", type="String", required=true, description="身份证反面")
     * @ApiParams   (name="identityhold", type="String", required=true, description="身份证手持")
     */
    public function authentication()
    {
        $user = ProxyUser::get($this->auth->id);
        $log = ProxyVerified::where(['user_id' => $user->id, 'status' => 0])->count();

        if ($user['authentication'] == 1) {
            $this->error('实名认证已通过，请勿重复提交');
        } else {
            if ($log > 0) {
                $this->error('认证资料审核中，请耐心等待', null, 1);
            }
        }

        $identityfront = $this->request->param('identityfront');
        $identityback = $this->request->param('identityback');
        $identityhold = $this->request->param('identityhold');
        $rule = [
            'identityfront' => 'require',
            'identityback' => 'require',
            'identityhold' => 'require',
        ];
        $msg = [
            'identityfront.require' => '请上传身份证正面',
            'identityback.require' => '请上传身份证反面',
            'identityhold.require' => '请上传手持身份证照片',
        ];
        $data = [
            'identityfront' => $identityfront,
            'identityback' => $identityback,
            'identityhold' => $identityhold,
        ];

        $validate = new Validate($rule, $msg);
        $result = $validate->check($data);
        if (!$result) {
            $this->error(__($validate->getError()));
        }
        $oss = new Oss();
        $idCardOrc = new IdCardOrc();

        $front = pathinfo($identityfront);
        $cahe_front = ROOT_PATH . '/runtime/upload/' . date('Ymd') . "/" . time() . rand(100, 999) . "." . $front['extension'];
        if ($oss->isExitFile($identityfront) && $oss->ossDownload($identityfront, $cahe_front)) {
            $save_front = "upload/" . date("Ymd") . "/" . md5_file($cahe_front) . "." . $front["extension"];
            if ($oss->ossUpload($cahe_front, $save_front)) {
                $data['identityfront'] = config('oss.url') . $save_front;
            }
        }

        $back = pathinfo($identityback);
        $cahe_back = ROOT_PATH . '/runtime/upload/' . date('Ymd') . "/" . time() . rand(100, 999) . "." . $back['extension'];
        if ($oss->isExitFile($identityback) && $oss->ossDownload($identityback, $cahe_back)) {
            $save_back = "upload/" . date("Ymd") . "/" . md5_file($cahe_back) . "." . $back["extension"];
            if ($oss->ossUpload($cahe_back, $save_back)) {
                $data['identityback'] = config('oss.url') . $save_back;
            }
        }

        $hold = pathinfo($identityhold);
        $cahe_hold = ROOT_PATH . '/runtime/upload/' . date('Ymd') . "/" . time() . rand(100, 999) . "." . $hold['extension'];
        if ($oss->isExitFile($identityhold) && $oss->ossDownload($identityhold, $cahe_hold)) {
            $save_hold = "upload/" . date("Ymd") . "/" . md5_file($cahe_hold) . "." . $hold["extension"];
            if ($oss->ossUpload($cahe_hold, $save_hold)) {
                $data['identityhold'] = config('oss.url') . $save_hold;
            }
        }

        $frontRes = $idCardOrc->check($cahe_front);//身份证正面检测
        if (isset($frontRes['AdvancedInfo'])) {
            $frontInfo = json_decode($frontRes['AdvancedInfo'], true);
            if (!empty($frontinfo['WarnInfos'])) {
                $errorMsg = $idCardOrc->error_code($frontInfo['WarnInfos'][0]);
                $this->error($errorMsg);
            }
        }


        $backRes = $idCardOrc->check($cahe_back, 1, 'BACK');//身份证背面检测
        if (isset($backRes['AdvancedInfo'])) {
            $backInfo = json_decode($backRes['AdvancedInfo'], true);
            if (!empty($backInfo['WarnInfos'])) {
                $errorMsg = $idCardOrc->error_code($backInfo['WarnInfos'][0]);
                $this->error($errorMsg);
            }
        }

        if (isset($backRes['code']) && $backRes['code'] == 0) {
            $this->error('证件无法识别，请重新上传');
        }
        if (isset($frontRes['code']) && $frontRes['code'] == 0) {
            $this->error('证件无法识别，请重新上传');
        } else {
            $log = [
                'name' => $frontRes['Name'],
                'num' => $frontRes['IdNum'],
                'region' => $frontRes['Address'],
            ];
        }

        $data = array_merge($data, $log);
        $ret = $this->auth->authentication($this->auth->id, $data);
        $user->save(['authentication' => 0]);
        if ($ret) {
            $this->success(__('认证提交成功,请耐心等待审核'), [], 200);
        } else {
            $this->error($this->auth->getError());
        }
    }

    /**
     * 用户剩余次数
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function user_desc()
    {
        $info = ProxyUser::where(['id' => $this->auth->id, 'status' => 'normal'])
            ->field('id,username,nickname,mobile,sign_num,rate,pid')
            ->find();
        $bale_rate_table = getTable("proxy_bale_rate", $info["pid"]);
        $total = Db::table($bale_rate_table)->where("user_id", $info["id"])
            ->where('status', 1)
            ->count();//总下载
        $today_num = Db::table($bale_rate_table)->where("user_id", $info["id"])
            ->where('status', 1)
            ->whereTime("create_time", "d")
            ->count();
        $info["download_num"] = $total;
        $info["today_num"] = $today_num;
        $info["sign_num"] = intval($info["sign_num"]);
        $this->success("success", $info, 200);
    }

    /***
     * 个签充值记录
     * @throws \think\Exception
     * @throws \think\exception\DbException
     */
    public function v1_pay_log(){
        $start = $this->request->post('start');
        $end = $this->request->post('end');
        $type = $this->request->post('type');
        $user_id = $this->auth->id;
        $user = ProxyUser::get($user_id);
        if (empty($user)) {
            $this->error('用户不存在');
        }
        $where=[
            "user_id"=>$user_id
        ];
        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 10);
        if (intval($page) < 1) $page = 1;
        $offset = ($page - 1) * $pageSize;
        $end && $end = $end . ' 23:59:59';
        $start && $end && $where['createtime'] = ['between', [strtotime($start), strtotime($end)]];
        if($type==1){
            $where["num"]=[">",0];
        }
        if($type == 2){
            $where["num"]=["<",0];
        }
        $total = ProxyUserMoneyLogV1::where($where)->count();
        $list = ProxyUserMoneyLogV1::where($where)
            ->order('createtime', 'desc')
            ->limit($offset, $pageSize)
            ->column('id,createtime,user_id,num,remark');
        foreach ($list as $k=>$v){
            if($v["num"]>0){
                $list[$k]["type"]=1;
            }else{
                $list[$k]["type"]=2;
            }
            $list[$k]["num"]=intval(abs($v["num"]));
            $list[$k]["create_time"]=date("Y-m-d H:i:s",$v["createtime"]);
        }
        $result = [
            'total' => $total,
            'currentPage' => $page,
            'list' => array_values($list)
        ];
        $this->result('success', $result, 200);
    }

}
