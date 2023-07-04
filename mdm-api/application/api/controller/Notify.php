<?php
namespace app\api\controller;

use app\common\controller\Api;
use app\common\library\Alipay;
use app\common\model\Config;
use app\common\model\ProxyUser as User;
use app\common\model\ProxyMoneyLog as MoneyLog;
use think\Db;
use app\common\model\PrivateOrder;
use app\common\model\PrivateNum;


/**
 * 回调
 * @ApiInternal
 */
class Notify extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];
    protected $alipayPublicKey;
    public function __construct()
    {
        $config = Config::where(['name'=>'alipay','group'=>'basic'])->value('value');
        $config = json_decode($config,true);
        $this->alipayPublicKey = $config['ali_public_key'];
    }
    /**
     * 支付同步回调
     */
    public function proxyReturnUrl()
    {
        $aliPay = new Alipay($this->alipayPublicKey);
        $download_url = $_GET['proxy_url'];
        unset($_GET['proxy_url']);
        //验证签名
        $result = $aliPay->rsaCheck($_GET,$_GET['sign_type']);
        if($result){
            $this->redirect(base64_decode($download_url));
        }
        echo '不合法的请求';exit();
    }
    /**
     * 私有池设备余额购买回调
     */
    public function privateMoneyNotify($order_sn)
    {
        $order = PrivateOrder::where(['order_sn' => $order_sn, 'status' => 0])->find();
        $user = User::get($order['user_id']);
        if($user['money'] < $order['amount']){
            return false;
        }
        $money = bcsub($user['money'],$order['amount'],2);
        $money_log_data=[
            'user_id'=>$user['id'],
            'num'=>$order['amount'],
            'before'=>$user['money'],
            'after'=>$money,
            'memo'=>'购买私有池设备次数',
            'createtime'=>time(),
        ];
        Db::startTrans();
        try{
            User::where('id',$user['id'])
                ->where('money',$user['money'])
                ->update(['money'=>$money]);
            MoneyLog::create($money_log_data);
            Db::commit();
        }catch (\Exception $e){
            Db::rollback();
            return false;
        }
        return $this->update_private_status($order_sn,array('transaction_id'=>'')); // 修改订单支付状态
    }

    /**
     * 私有池设备回调
     */
    public function privateNotify()
    {
        $aliPay = new Alipay($this->alipayPublicKey);
        //验证签名
        $result = $aliPay->rsaCheck($_POST,$_POST['sign_type']);
        if((bool)$result){
            $order_sn = $out_trade_no = $_POST['out_trade_no']; //商户订单号
            $trade_no = $_POST['trade_no']; //支付宝交易号
            $order_amount = PrivateOrder::where(['order_sn' => $order_sn, 'status' => 0])->value('amount');
            if($order_amount!=$_POST['total_amount']){
                exit("fail"); //验证失败
            }
            // 支付宝解释: 交易成功且结束，即不可再做任何操作。
            if($_POST['trade_status'] == 'TRADE_FINISHED')
            {
                $this->update_private_status($order_sn,array('transaction_id'=>$trade_no)); // 修改订单支付状态
            }
            //支付宝解释: 交易成功，且可对该交易做操作，如：多级分润、退款等。
            elseif ($_POST['trade_status'] == 'TRADE_SUCCESS')
            {
                $this->update_private_status($order_sn,array('transaction_id'=>$trade_no)); // 修改订单支付状态
            }
            //你可以在这里你的业务处理逻辑,比如处理你的订单状态、给会员加余额等等功能
            //下面这句必须要执行,且在此之前不能有任何输出
            echo "success";
            return;
        }
        echo 'error';exit();
    }
    /**
     * 修改私有池订单状态
     * @param $order_sn
     * @param array $ext
     * @return bool
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function update_private_status($order_sn,$ext=array())
    {
        $time =date('Y-m-d H:i:s');
        //用户在线充值
        $order = PrivateOrder::where(['order_sn' => $order_sn, 'status' => 0])->find();
        if (!$order) return false;// 看看有没已经处理过这笔订单  支付宝返回不重复处理操作
        PrivateOrder::where("order_sn",$order_sn)->update(
            array(
                'status'=>1,
                'pay_time'=>$time,
                'transaction_id'=>$ext['transaction_id'],
            )
        );
        $private_num = User::where(['id'=>$order['user_id']])->value('private_num');

        $after = bcadd($private_num,$order['num']);

        $log_data = [
            'user_id' => $order['user_id'],
            'num' => $order['num'],
            'before' =>$private_num,
            'after' => $after,
            'memo' => '用户购买私有池设备次数',
            'create_time' => $time,
        ];
        Db::startTrans();
        try{
            User::where('id',$order['user_id'])
                ->where('private_num',$private_num)
                ->update(['private_num'=>$after]);
            PrivateNum::create($log_data);
            Db::commit();
        }catch (\Exception $e){
            Db::rollback();
            return false;
        }
        return true;
    }
}