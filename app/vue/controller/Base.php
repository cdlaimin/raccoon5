<?php


namespace app\vue\controller;


use app\BaseController;
use think\facade\Env;

class Base extends BaseController
{
    protected $prefix;
    protected $redis_prefix;
    protected $uid;
    protected $end_point;
    protected $tpl;
    protected $links;
    protected $url;

    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $token = $this->request->param('token');
        $time = $this->request->param('time');
        if (time() - $time > 180) {
            return json(['success' => 0, 'msg' => '过期请求'])->send();
        }
        $key = config('site.app_key');
        if ($token != md5($key . $time)) {
            return json(['success' => 0, 'msg' => '未授权请求'])->send();
        }

        $this->prefix = Env::get('database.prefix');
        $this->redis_prefix = Env::get('cache.prefix');
        $this->url = config('site.schema').config('site.domain');
        $this->book_ctrl = BOOKCTRL;
    }
}