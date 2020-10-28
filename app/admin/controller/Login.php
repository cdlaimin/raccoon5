<?php


namespace app\admin\controller;


use app\BaseController;
use think\db\exception\ModelNotFoundException;
use app\model\Admin;
use think\facade\View;

class Login extends BaseController
{
    public function login(){
        if (request()->isPost()){
            $captcha = request()->param('captcha');
            if( !captcha_check($captcha))
            {
                return json(['err' => 1, 'msg' => '验证码错误']);
            }
            $username = request()->param('admin');
            $password = trim(request()->param('password'));
            $map = array();
            $map[] = ['username','=',$username];
            $map[] = ['password', '=', $password];
            try {
                $admin = Admin::where($map)->findOrFail();
            }  catch (ModelNotFoundException $e) {
                return json(['err' => 1, 'msg' => '用户名或密码错误']);
            }
            $admin->last_login_time = time();
            $admin->last_login_ip = $this->request->ip();
            $admin->save();
            cookie('xwx_admin',$admin->username);
            return json(['err' => 0, 'msg' => '登录成功']);
        }else{
            View::assign('cdn',config('site.cdn'));
            return view();
        }
    }

    public function logout(){
        cookie('xwx_admin',null);
        return redirect(url('login/login'));
    }

    public function captcha()
    {
        ob_clean();
        return captcha();
    }

}