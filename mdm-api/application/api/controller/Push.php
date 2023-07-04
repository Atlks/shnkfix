<?php

namespace app\api\controller;


use app\common\controller\Api;
use app\common\model\ProxyApp;
use app\common\model\AppDevicetoken;
use app\common\model\AppPushLog;
use think\validate;
/**
 * 消息推送
 */
class Push extends Api
{
    protected $noNeedLogin = '';
    protected $noNeedRight = ['*'];

    /**
     * 创建消息推送计划
     * @ApiMethod (POST)
     * @ApiHeaders	(name=token, type=string, required=true, description="请求的Token")
     * @ApiParams   (name="app_id", type="Int", required=true, description="应用ID")
     * @ApiParams   (name="title", type="String", required=true, description="标题")
     * @ApiParams   (name="subtitle", type="String", required=true, description="子标题")
     * @ApiParams   (name="msg", type="String", required=true, description="推送内容")
     * @ApiParams   (name="now", type="Int", required=true, description="1-立即推送0-定时推送")
     * @ApiParams   (name="time", type="String", required=false, description="定时推送时间Y-m-d H:i:s")
     */
    public function sendMessage()
    {
        $params = $this->request->post();
        $rule = [
            'app_id' => 'require',
            'title' => 'require|min:2|max:15',
            'subtitle' => 'min:2|max:15',
            'msg' => 'require|min:2|max:50',
        ];
        $msg = [
            'required' => ':attribute不能为空',
            'min' => ':attribute最小长度为:min',
            'max' => ':attribute最大长度为:max',
        ];
        $attributes = [
            'app_id' => '应用ID',
            'title' => '标题',
            'subtitle' => '子标题',
            'msg' => '内容',
        ];
        $data = [
            'app_id'  => $params['app_id'],
            'title'  => $params['title'],
            'subtitle'  => $params['subtitle'],
            'msg'  => $params['msg'],
        ];
        $validate = new Validate($rule, $msg, $attributes);
        $result = $validate->check($data);
        if (!$result) {
            $this->error(__($validate->getError()));
        }

        $app = ProxyApp::get(['id'=>$params['app_id'],'user_id'=>$this->auth->id]);
        if(empty($app)){
            $this->error(__('应用不存在'));
        }
        $file = $app['cert_path'];
        if(empty($file)){
            $this->error(__('请上传应用证书'));
        }
        if($app['push_type'] == 2){
            $where = [
                'app_id' => $params['app_id'],
                'bundle' => $app['package_name'],
                'system_version' => str_replace('.','',$app['version_code']),
            ];
        }else{
            $where = [
                'app_id' => $params['app_id']
            ];
        }
        $tokens = AppDevicetoken::where($where)->column('token');
        if(empty($tokens)){
            $this->error(__('暂无相关用户'));
        }
//        $sendTokens = [];
//        foreach ($tokens as $v){
//            if(!empty($v) && strpos($v,'{length') === false){
//                $sendTokens[] = $v;
//            }
//        }
//        if(empty($sendTokens)){
//            $this->error(__('暂无相关用户'));
//        }
        if($params['now'] == 1)
        {
            $time = 5;//10s
        }else{
            if(empty($params['time'])){
                $this->error(__('请选择推送时间'));
            }
            $time = bcsub(strtotime($params['time']),time());
        }

        $add_data = [
            'app_id' => $params['app_id'],
            'user_id' => $this->auth->id,
            'title' => $params['title'],
            'subtitle' => $params['subtitle'],
            'msg' => $params['msg'],
            'type' => $params['now'],
            'equipment_num' => count($tokens),//推送设备数
            'push_time' => date('Y-m-d H:i:s',bcadd(time(),$time)),
            'create_time' => date('Y-m-d H:i:s'),
        ];
        try{
            $log = AppPushLog::create($add_data);
        }catch (\Exception $e){
            $this->error(__('创建推送计划失败'),$e->getMessage());
        }

        if($params['now'] == 1)
        {
            $url=config('ios_push_domain');
            $params = array_merge($params,['cert_path'=>$file,'deviceToken'=>json_encode($tokens),'time'=>$time,'push_id'=>$log['id'],'send_type'=>'proxy','tag'=>$app['tag']]);
            $this->http_request($url,$params);
        }
        $this->success('创建推送计划成功', [], 200);

    }

    /**
     * 推送计划列表
     * @ApiWeigh  1
     * @ApiMethod (POST)
     * @ApiHeaders	(name=token, type=string, required=true, description="请求的Token")
     * @ApiParams (name="app_id", type="Int", required=true, description="应用ID")
     * @ApiParams   (name="start", type="String", required=false, description="开始时间 Y-m-d")
     * @ApiParams   (name="end", type="String", required=false, description="结束时间")
     * @ApiParams   (name="page", type="Int", required=false, description="当前页")
     * @ApiParams   (name="page_size", type="Int", required=false, description="每页显示条数")
     * @ApiReturnParams (name="status", type="integer", required=true, description="状态 0 未推送 1已推送")
     * @ApiReturnParams (name="is_deleted", type="integer", required=true, description="删除：1正常0删除")
     * @ApiReturnParams (name="equipment_num", type="Int", required=true, description="设备总数")
     * @ApiReturnParams (name="push_time", type="String", required=true, description="推送时间")
     * @ApiReturnParams (name="push_type", type="Int", required=true, description="1-证书推送2-直接推送")
     * @ApiReturnParams (name="cert_path", type="String", required=true, description="证书1-存在0-不存在")
     * @ApiReturnParams (name="total", type="Int", required=true, description="应用总条数")
     * @ApiReturnParams (name="currentPage", type="Int", required=true, description="当前页")
     */
    public function pushLog()
    {
        $id = $this->request->post('app_id');
        $start = $this->request->post('start');
        $end = $this->request->post('end');
        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 10);
        if (intval($page) < 1) $page = 1;
        $offset = ($page - 1) * $pageSize;

        if(!$id){
            $this->error(__('参数错误'));
        }
        $app = ProxyApp::get($id);
        if(empty($app)){
            $this->error(__('应用不存在'));
        }
        $where['app_id'] = $id;
        $where['user_id'] = $this->auth->id;
        $start && $end && $where['push_time'] = ['between',[$start,$end]];

        $total = AppPushLog::where($where)->count();

        $list = AppPushLog::where($where)
            ->field('id,push_time,equipment_num,status,is_deleted')
            ->order('push_time', 'desc')
            ->limit($offset, $pageSize)
            ->select();

        $result = [
            'total' => $total,
            'currentPage' => $page,
            'push_type'=>$app['push_type'],
            'cert_path'=>$app['cert_path']?1:0,
            'list' => array_values($list),
        ];

        $this->result('success', $result, 200);
    }

    /**
     * 推送操作
     * @ApiWeigh  1
     * @ApiMethod (POST)
     * @ApiHeaders	(name=token, type=string, required=true, description="请求的Token")
     * @ApiParams (name="id", type="Int", required=true, description="推送计划ID")
     * @ApiParams   (name="type", type="Int", required=true, description="操作类型;1-删除;2-查看详情")
     */
    public function pushHandle()
    {
        $id = $this->request->post('id');
        $type = $this->request->param('type');
        if(!$id || !$type){
            $this->error(__('参数错误'));
        }
        $log = AppPushLog::get(['id'=>$id,'user_id'=>$this->auth->id]);
        if(empty($log)){
            $this->error(__('推送计划不存在'));
        }
        $result = [];
        switch($type)
        {
            case 1;
                try {
                    AppPushLog::where(['id'=>$id])->delete();
                } catch (\Exception $e) {
                    $this->error('操作失败');
                }
                break;
            case 2;
                $result = AppPushLog::get(['id'=>$id,'user_id'=>$this->auth->id]);
                break;
            default ;
                $this->error(__('Invalid parameters'));
                break;
        }


        $this->result('success', $result, 200);
    }

    /**
     * app推送设备列表
     * @ApiWeigh  3
     * @ApiMethod (POST)
     * @ApiHeaders	(name=token, type=string, required=true, description="请求的Token")
     * @ApiParams (name="id", type="Int", required=true, description="AppID")
     * @ApiParams   (name="type", type="Int", required=true, description="操作类型;1-删除;2-查看详情")
     */
    public function pushUserList()
    {

    }
}
