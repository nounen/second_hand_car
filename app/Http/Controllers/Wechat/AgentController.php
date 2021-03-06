<?php

namespace App\Http\Controllers\Wechat;

use App\Http\Requests\Wechat\AgentPost;
use App\Http\Requests\Wechat\PayPost;
use App\Models\Car;
use App\Models\Pay;
use App\Models\WechatUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentController extends Controller
{
    protected $wechatUser;

    public function __construct(WechatUser $wechatUser)
    {
        parent::__construct();

        $this->wechatUser = $wechatUser;
    }

    /**
     * 我要代理
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|\Illuminate\View\View
     */
    public function create()
    {
        // 已经是代理人就跳转到 代理中心
        if ($this->auth->wechat_user_type == WechatUser::AGENT_CODE) {
            return redirect('wechat/agent/center');
        }

        $this->data['page_title'] = '我要代理';

        $this->data['sex'] = (new WechatUser())->sexes;

        $this->data['user'] = $this->guard()->user();        

        return view('wechat.agent.create', $this->data);
    }

    /**
     * 我要代理数据处理
     *
     * @param AgentPost $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function store(AgentPost $request)
    {
        // 已经是代理人就跳转到 代理中心
        if ($this->auth->wechat_user_type == WechatUser::AGENT_CODE) {
            return redirect('wechat/agent/center');
        }

        $input = $request->only([
            'name',
            'sex',
            'phone',
            'hangye',
            'job',
        ]);

        $input['wechat_user_type'] = WechatUser::APPLY_AGENT_CODE;

        $this->auth->update($input);

        return redirect('wechat/car/create');
    }

    /**
     * 代理中心
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function center()
    {
        $this->data['page_title'] = '代理中心';

        return view('wechat.agent.center', $this->data);
    }

    /**
     * 代理规则
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function rule()
    {
        $this->data['page_title'] = '代理规则';

        return view('wechat.agent.rule', $this->data);
    }

    /**
     * 推广二维码
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function qrCode()
    {
        $this->data['page_title'] = '推广二维码';

        $this->data['qrcode_url'] = $this->auth->agent_qrcode_url;

        return view('wechat.agent.qr_code', $this->data);
    }

    /**
     * 我的团队
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function myUser()
    {
        $this->data['page_title'] = '我的团队';

        $this->data['my_users'] = $this->wechatUser->getMyUserList($this->auth->id);

        return view('wechat.agent.my_user', $this->data);
    }

    /**
     * 我的车库
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function myCar(Request $request, Car $car)
    {
        $this->data['page_title'] = '我的车库';

        $this->data['current_car_state'] = $carSteate = $request->get('car_state', Car::NEW_CAR_CODE);

        $this->data['my_cars'] = $car->getMyListByState($this->auth, $carSteate);

        $this->data['car_states'] = $car->getMyListCarStates();

        return view('wechat.agent.my_car', $this->data);
    }

    /**
     * 申请提现
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function apply(Pay $pay)
    {
        $this->data['page_title'] = '申请提现';

        $this->data['pay_types'] = $pay->getApplyPayTypes();

        $this->data['can_get_price'] = $this->auth->can_get_price;

        $this->data['getting_price'] = $this->auth->getting_price;

        $this->data['has_get_price'] = $this->auth->has_get_price;

        return view('wechat.agent.apply', $this->data);
    }

    /**
     * 申请提现数据处理
     *
     * @param PayPost $request
     * @param Pay $pay
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function applyPost(PayPost $request, Pay $pay, WechatUser $wechatUser)
    {
        $price = (float)$request->get('price');

        // 输入金额特殊情况处理
        if ($price <= 0) {
            return redirect(url('wechat/agent/my_account'));
        }

        DB::transaction(function () use ($request, $pay, $wechatUser) {
            $input = $request->only([
                'pay_type',
                'account',
                'real_name',
                'price',
            ]);

            $input['wechat_user_id'] = $this->auth->id;

            $input['pay_state'] = Pay::STATE_WAIT; // 待处理

            $pay->create($input);

            // 可提现金额 减少
            $this->auth->can_get_price = (float)$this->auth->can_get_price - (float)$input['price'];

            // 处理中金额 增加
            $this->auth->getting_price = (float)$this->auth->getting_price + (float)$input['price'];

            $this->auth->save();
        });

        return redirect(url('wechat/agent/my_account'));
    }

    /**
     * 提现记录
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function myAccount(Pay $pay)
    {
        $this->data['page_title'] = '提现记录';

        $this->data['my_applies'] = $pay->getMyAccountList($this->auth);

        return view('wechat.agent.my_account', $this->data);
    }
}