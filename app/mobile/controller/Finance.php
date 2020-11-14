<?php


namespace app\mobile\controller;


use app\model\Book;
use app\model\Chapter;
use app\model\ChargeCode;
use app\common\RedisHelper;
use app\model\User;
use app\model\UserBuy;
use app\model\UserFinance;
use app\model\UserOrder;
use app\model\VipCode;
use app\pay\Pay;
use app\service\FinanceService;
use app\service\PromotionService;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\facade\Cache;
use think\facade\Db;
use think\facade\View;

class Finance extends BaseUc
{
    protected $financeService;
    protected $balance;

    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->financeService = app('financeService');
        $this->balance = session('balance'); //当前用户余额
        if (!$this->balance) {
            $this->balance = $this->financeService->getBalance($this->uid);
            session('balance', $this->balance);
        }
    }

    //用户钱包
    public function wallet()
    {
        $charge_sum = cache('chargesum:' . $this->uid);
        if (!$charge_sum) {
            $charge_sum = $this->financeService->getChargeSum($this->uid);
            cache('chargesum:' . $this->uid, $charge_sum, '', 'pay');
        }
        $spending_sum = cache('spending_sum:' . $this->uid);
        if (!$spending_sum) {
            $spending_sum = $this->financeService->getSpendingSum($this->uid);
            cache('spending_sum:' . $this->uid, $charge_sum, '', 'pay');
        }
        View::assign([
            'balance' => $this->balance,
            'charge_sum' => $charge_sum,
            'spending_sum' => $spending_sum,
            'header_title' => '我的钱包'
        ]);
        return view($this->tpl);
    }

    //充值记录
    public function chargehistory()
    {
        $charges = $this->financeService->getUserChargeHistory($this->uid);

        $charge_sum = cache('chargesum:' . $this->uid);
        if (!$charge_sum) {
            $charge_sum = $this->financeService->getChargeSum($this->uid);
            cache('chargesum:' . $this->uid, $charge_sum, '', 'pay');
        }

        View::assign([
            'balance' => $this->balance,
            'charges' => $charges,
            'charge_sum' => $charge_sum,
            'header_title' => '充值记录'
        ]);
        return view($this->tpl);
    }

    public function spendinghistory()
    {
        $spendings = $this->financeService->getUserSpendingHistory($this->uid);

        $spending_sum = cache('spendingsum:' . $this->uid);
        if (!$spending_sum) {
            $spending_sum = $this->financeService->getSpendingSum($this->uid);
            cache('spendingsum:' . $this->uid, $spending_sum, '', 'pay');
        }

        View::assign([
            'balance' => $this->balance,
            'spendings' => $spendings,
            'spending_sum' => $spending_sum,
            'header_title' => '消费记录'
        ]);
        return view($this->tpl);
    }

    public function buyhistory()
    {
        View::assign([
            'header_title' => '购买的作品'
        ]);
        return view($this->tpl);
    }

    public function getBuyHistory() {
        $page = input('page');
        try {
            $data = UserBuy::where('user_id', '=', $this->uid)->order('id', 'desc')->limit($page, 15)->selectOrFail();
            foreach ($data as &$buy) {
                $book = Book::findOrFail($buy['book_id']);
                $chapter = Chapter::findOrFail($buy['chapter_id']);
                if ($this->end_point == 'id') {
                    $book['param'] = $book['id'];
                } else {
                    $book['param'] = $book['unique_id'];
                }
                $buy['book'] = $book;
                $buy['chapter'] = $chapter;
            }
            return json(['err' => 0, 'buys' => $data]);
        } catch (DataNotFoundException $e) {
            return json(['err' => 1]);
        } catch (ModelNotFoundException $e) {
            return json(['err' => 1]);
        }
    }

    //处理充值
    public function charge()
    {
        if (request()->isPost()) {
            $money = request()->post('money'); //用户充值金额
            $pay_type = request()->post('pay_type'); //充值渠道
            $pay_code = request()->post('code');
            $order = new UserOrder();
            $order->user_id = $this->uid;
            $order->money = $money;
            $order->status = 0; //未完成订单
            $order->pay_type = $pay_type;
            $order->expire_time = time() + 86400; //订单失效时间往后推一天
            $res = $order->save();
            if ($res) {
                $pay = new Pay();
                $pay->submit('xwx_order_' . $order->id, $money, $pay_type, $pay_code); //调用功能类，进行充值处理
                $this->balance = $this->financeService->getBalance($this->uid);
                session('balance', $this->balance); //更新session信息
            }
        } else {
            $payment = strtolower(config('site.payment'));
            $payments = config('payment.pay.channel');
            View::assign([
                'balance' => $this->balance,
                'moneys' => config('payment.money'),
                'payments' => $payments,
                'header_title' => '用户充值'
            ]);
            return view($this->tpl);
        }
    }

    public function Kami()
    {
        if (request()->isPost()) {
            $str_code = trim(input('code'));
            try {
                $code = ChargeCode::where('code', '=', $str_code)->findOrFail();
                if ((int)$code->used == 3) {
                    return json(['err' => 1, 'msg' => '该充值码已经被使用']);
                }

                $code->used = 3; //变更状态为使用
                $code->update_time = time();
                $res = $code->save();
                if ($res) {
                    $order = new UserOrder();
                    $order->user_id = $this->uid;
                    $order->money = $code->money;
                    $order->status = 1; //完成订单
                    $order->pay_type = 4;
                    $order->summary = $str_code; //备注卡密
                    $order->expire_time = time() + 86400; //订单失效时间往后推一天
                    $order->save();

                    $userFinance = new UserFinance();
                    $userFinance->user_id = $this->uid;
                    $userFinance->money = $code->money; //充值卡面额
                    $userFinance->usage = 1; //用户充值
                    $userFinance->summary = '卡密充值';
                    $userFinance->save(); //存储用户充值数据

                    $promotionService = new PromotionService();
                    $promotionService->rewards($this->uid, $code->money, 1); //调用推广处理函数
                    return json(['err' => 0, 'msg' => '充值码使用成功']);
                } else {
                    return json(['err' => 1, 'msg' => '充值码使用失败']);
                }
            } catch (DataNotFoundException $e) {
                return json(['err' => 1, 'msg' => '该充值码不存在']);
            } catch (ModelNotFoundException $e) {
                return json(['err' => 1, 'msg' => '该充值码不存在']);
            }
        }
        $url = config('payment.kami.url');
        $newest = cache('newest_homepage');
        if (!$newest) {
            $bookService = new \app\service\BookService();
            $newest = $bookService->getBooks($this->end_point, 'last_time', '1=1', 14);
            cache('newest_homepage', $newest, null, 'redis');
        }
        View::assign([
            'url' => $url,
            'books' => $newest,
        ]);
        return view($this->tpl);
    }

    //用户支付回跳网址
    public function feedback()
    {

        View::assign([
            'balance' => $this->balance,
            'header_title' => '支付成功'
        ]);
        return view($this->tpl);
    }

    public function buychapter()
    {
        $id = input('chapter_id');
        $chapter = Chapter::with(['photos' => function ($query) {
            $query->order('pic_order');
        }, 'book'])->cache('chapter:' . $id, 600, 'redis')->find($id);
        $price = $chapter->book->money; //获得单章价格
        if ($this->request->isPost()) {
            $redis = RedisHelper::GetInstance();
            if (!$redis->exists($this->redis_prefix . ':user_buy_lock:' . $this->uid)) { //如果没上锁，则该用户可以进行购买操作
                $this->balance = $this->financeService->getBalance($this->uid); //这里不查询缓存，直接查数据库更准确
                if ($price > $this->balance) { //如果价格高于用户余额，则不能购买
                    return json(['err' => 1, 'msg' => '余额不足']);
                } else {
                    $userFinance = new UserFinance();
                    $userFinance->user_id = $this->uid;
                    $userFinance->money = $price;
                    $userFinance->usage = 3;
                    $userFinance->summary = '购买章节';
                    $userFinance->save();

                    $userBuy = new UserBuy();
                    $userBuy->user_id = $this->uid;
                    $userBuy->chapter_id = $id;
                    $userBuy->book_id = $chapter->book_id;
                    $userBuy->money = $price;
                    $userBuy->summary = '购买章节';
                    $userBuy->save();
                }
                $redis->set($this->redis_prefix . ':user_buy_lock:' . $this->uid, 1, 5);
                Cache::clear('pay'); //删除缓存
                return json(['err' => 0, 'msg' => '购买成功，等待跳转']);
            } else {
                return json(['err' => 1, 'msg' => '同账号非法操作']);
            }
        }
        View::assign([
            'balance' => $this->balance,
            'chapter' => $chapter,
            'price' => $price
        ]);
        return view($this->tpl);
    }

    //vip会员页面
    public function vip()
    {
        try {
            $user = User::findOrFail($this->uid);
            if (request()->isPost()) {
                $redis = RedisHelper::GetInstance();
                if (!$redis->exists($this->redis_prefix . ':user_buy_lock:' . $this->uid)) { //如果没上锁，则该用户可以进行购买操作
                    $arr = config('payment.vip'); //拿到vip配置数组
                    $month = (int)request()->param('month'); //拿到用户选择的vip
                    $this->balance = $this->financeService->getBalance($this->uid); //这里不查询缓存，直接查数据库更准确
                    foreach ($arr as $key => $value) {
                        if ((int)$value['month'] == $month) {
                            if ((int)$value['price'] > $this->balance) { //如果vip价格大于用户余额
                                return json(['err' => 1, 'msg' => '余额不足，请先充值']);
                            } else { //处理购买vip的订单
                                $finance = new UserFinance();
                                $finance->user_id = $this->uid;
                                $finance->money = (int)$value['price'];
                                $finance->usage = 2;
                                $finance->summary = '购买vip';
                                $finance->save();

                                $user->level = 1; //vip用户
                                if ($user->vip_expire_time < time()) { //说明vip已经过期
                                    $user->vip_expire_time = time() + $month * 30 * 24 * 60 * 60;
                                } else { //vip没过期，则在现有vip时间上增加
                                    $user->vip_expire_time = $user->vip_expire_time + $month * 30 * 24 * 60 * 60;
                                }
                                $user->save();
                                session('vip_expire_time', $user->vip_expire_time);
                                Cache::clear('pay'); //删除缓存
                                return json(['err' => 0, 'msg' => '购买成功，等待跳转']);
                            }
                        }
                    }
                    $redis->set($this->redis_prefix . ':user_buy_lock:' . $this->uid, 1, 5);
                    return json(['err' => 1, 'msg' => '请选择正确的选项']); //以防用户篡改页面的提交值
                } else {
                    return json(['err' => -1, 'msg' => '同账号非法操作']);
                }
            }

            $time = $user->vip_expire_time - time();
            $day = 0;
            if ($time > 0) {
                $day = ceil(($user->vip_expire_time - time()) / (60 * 60 * 24));
            }
            View::assign([
                'balance' => $this->balance,
                'header_title' => 'vip会员',
                'user' => $user,
                'day' => $day,
                'vips' => config('payment.vip')
            ]);
            return view($this->tpl);
        } catch (DataNotFoundException $e) {
            abort(404, '用户不存在');
        } catch (ModelNotFoundException $e) {
            abort(404, '用户不存在');
        }

    }

    public function vipexchange()
    {
        if ($this->request->isPost()) {
            $str_code = trim(input('code'));
            try {
                $user = User::findOrFail($this->uid);
                $code = VipCode::where('code', '=', $str_code)->findOrFail();
                if ((int)$code->used == 3) {
                    return json(['err' => 1, 'msg' => '该vip码已经被使用']);
                }

                Db::startTrans();
                Db::table($this->prefix . 'vip_code')->update([
                    'used' => 3, //变更状态为使用
                    'id' => $code->id,
                    'update_time' => time()
                ]);

                $vip_expire_time = (int)$user->vip_expire_time;
                if ($vip_expire_time < time() ) { //说明vip已经过期
                    $new_expire_time = strtotime('+' . (int)$code->add_day . ' days', time());
                } else { //vip没过期，则在现有vip时间上增加
                    $new_expire_time = strtotime('+' . (int)$code->add_day . ' days', $vip_expire_time);
                }

                Db::table($this->prefix . 'user')->update([
                    'vip_expire_time' => $new_expire_time,
                    'id' => $this->uid
                ]);
                // 提交事务
                Db::commit();
                session('vip_expire_time', $new_expire_time);

                return json(['err' => 0, 'msg' => 'vip码使用成功']);
            } catch (DataNotFoundException $e) {
                abort(404, $e->getMessage());
            } catch (ModelNotFoundException $e) {
                abort(404, $e->getMessage());
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                return json(['err' => 1, 'msg' => $e->getMessage()]);
            }
        }

        $newest = cache('newest_homepage');
        if (!$newest) {
            $bookService = new \app\service\BookService();
            $newest = $bookService->getBooks($this->end_point, 'last_time', '1=1', 14);
            cache('newest_homepage', $newest, null, 'redis');
        }

        View::assign([
            'header_title' => 'vip会员',
            'books' => $newest
        ]);
        return view($this->tpl);
    }
}