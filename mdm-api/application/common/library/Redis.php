<?php


namespace app\common\library;


use think\Env;

class Redis
{
    protected $options;


    protected $redis;

    /**
     * 构造函数
     * @param array $options 缓存参数
     * @access public
     */
    public function __construct($options = [])
    {
        $this->options=[
            'host' => '34.96.144.207',
            'port' => '6379',
            'password' => '9kE4eVFFcTtIv70M',
            'select' => 1,
            'timeout' => 0,
            'expire' => 0,
            'persistent' => false,
            'prefix' => '',
        ];

        if (!extension_loaded('redis')) {
            throw new \BadFunctionCallException('not support: redis');
        }
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        $this->redis = new \Redis();
        if ($this->options['persistent']) {
            $this->redis->pconnect($this->options['host'], $this->options['port'], $this->options['timeout'], 'persistent_id_' . $this->options['select']);

        } else {
            $this->redis->connect($this->options['host'], $this->options['port'], $this->options['timeout']);
        }

        if ('' != $this->options['password']) {
            $this->redis->auth($this->options['password']);
        }

        if (0 != $this->options['select']) {
            $this->redis->select($this->options['select']);
        }
    }

    /**
     * 清空整个redis服务器
     */
    public function clearAll()
    {
        $this->redis->flushAll();
    }

    /**
     * 删除集合
     * @param string $key
     * @return int
     */
    public function delete(string $key)
    {
        $result = $this->redis->del($key);
        return $result;
    }

    /**
     * 返回句柄
     * @return \Redis
     */
    public function handle()
    {
        return $this->redis;
    }

    /**
     * 检查给定的KEY是否存在
     * @param string $key
     * @return bool
     */
    public function exists(string $key)
    {
        return $this->redis->exists($key);
    }

    /**
     * 设置过期时间
     * @param string $key
     * @param int $timeout
     * @return bool
     */
    public function expire(string $key, int $timeout)
    {
        return $this->redis->expire($key, $timeout);
    }

    /**
     * 查找所有符合给定模式 pattern 的 key
     * @param string $pattern
     * @return array
     */
    public function keys(string $pattern)
    {
        return $this->redis->keys($pattern);
    }

    /**
     * 将key移动到指定的库
     * @param string $key
     * @param int $select
     * @return bool
     */
    public function move(string $key, int $select)
    {
        return $this->redis->move($key, $select);
    }

    public static function hMSet($key,$value=[],$select=5){
        if(empty($value)){
            return  true;
        }
        $self = new self(["select"=>$select]);
        $result = $self->handle()->hMSet($key,$value);
        $self->handle()->close();
        return  $result;
    }

    public static function hGetAll($key,$select=5){
        $self = new self(["select"=>$select]);
        $result = $self->handle()->hGetAll($key);
        $self->handle()->close();
        return  $result;
    }

    public static function hUpdateVals($key,$value=[],$select=5){
        if(empty($value)){
            return  true;
        }
        $self = new self(["select"=>$select]);
        foreach ($value as $k=>$v){
            $self->handle()->hSet($key,$k,$v);
        }
        $self->handle()->close();
        return  true;
    }

    public static function resignLog($key,$log){
        $self = new self(['select' => 9]);
        $self->handle()->set($key, $log, 600);
        $self->handle()->close();
        return true;
    }

    /**
     * 设置缓存
     * @param $key
     * @param $val
     * @param int $time
     * @param array $config
     */
    public static function set($key, $val, $time = 180,$select=4)
    {
        if(empty($val)){
            return  true;
        }
        $self = new self(['select' => $select]);
        $self->handle()->set($key, json_encode($val), $time);
        $self->handle()->close();
    }


    public static function del($key,$select=5 )
    {
        if(empty($key)||$key=="*"){
            return  true;
        }
        $self = new self(['select' => $select]);
        $self->handle()->del($key);
        $self->handle()->close();
    }


    public static function get($key,$select=4)
    {
        $self = new self(['select' => $select]);
        $result = $self->handle()->get($key);
        $self->handle()->close();
        if(!empty($result)){
            return json_decode($result,true);
        }else{
            return null;
        }

    }


}
