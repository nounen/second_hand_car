<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\CarDealNewPost;
use App\Http\Requests\Admin\CarDealTalkPost;
use App\Models\Car;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CarController extends Controller
{
    protected $car;

    public function __construct(Car $car)
    {
        parent::__construct();

        $this->car = $car;

        $this->data['cars_count'] = $this->car->countByState();
    }

    public function index(Request $request, Car $car)
    {
        $this->data['page_title'] = '车库列表';

        $this->data['current_car_state'] = $carSteate = $request->get('car_state', Car::NEW_CAR_CODE);

        $cars = $car->getListByState($carSteate);

        // 不同状态的车辆进入不同的链接
        foreach ($cars AS &$car) {
            switch ($car->car_state) {
                case Car::NEW_CAR_CODE:
                    $car->url = url("admin/car/{$car->id}/deal_new");
                    break;
                case Car::TALK_CAR_CODE:
                    $car->url = url("admin/car/{$car->id}/deal_talk");
                    break;
                case Car::DONE_CAR_CODE:
                    $car->url = url("admin/car/{$car->id}");
                    break;
                default:
                    $car->url = url("admin/car/{$car->id}");
                    break;
            }
        }

        unset($car);

        $this->data['cars'] = $cars;

        return view('admin.car.index', $this->data);
    }

    public function dealNew($id)
    {
        $this->data['page_title'] = '新入库处理操作';

        $this->data['car'] = $this->car->find($id);

        $this->data['car_states'] = $this->car->getDealNewCarStates();

        return view('admin.car.deal_new', $this->data);
    }

    public function dealNewUpdate(CarDealNewPost $request, $id)
    {
        $car = $this->car->find($id);

        if ($car->car_state != Car::NEW_CAR_CODE) {
            return redirect(url("admin/car/{$car}"));
        } else {
            $car->car_state = $request->get('car_state');
            $car->admin_user_id = $this->auth->id;
            $car->save();

            return redirect(url("admin/car?car_state={$car->car_state}"));
        }
    }

    public function dealTalk($id)
    {
        $car = $this->car->find($id);

        // 已成交 或 未成交 跳转至详情页
        if (in_array($car->car_state, [Car::DONE_CAR_CODE, Car::UNDONE_CAR_CODE])) {
            return redirect(url("admin/car/{$id}"));
        }

        // 新入库 跳转至更新状态页
        if (in_array($car->car_state, [Car::NEW_CAR_CODE])) {
            return redirect(url("admin/car/{$id}/deal_new"));
        }

        $this->data['page_title'] = '洽谈中处理操作';

        $this->data['car'] = $car;

        $this->data['car_states'] = $this->car->getDealTalkCarStates();

        return view('admin.car.deal_talk', $this->data);
    }

    public function dealTalkUpdate(CarDealTalkPost $request, $id)
    {
        $car = $this->car->find($id);

        // 不是洽谈中的车就跳转到详情
        if ($car->car_state != Car::TALK_CAR_CODE) {
            return redirect(url("admin/car/{$id}"));
        } else {
            $input = [];

            switch ($request->get('car_state')) {
                case Car::TALK_CAR_CODE:
                    $input = $request->only([
                        'car_state',
                        'description',
                    ]);

                    break;
                case Car::DONE_CAR_CODE:
                    $input = $request->only([
                        'car_state',
                        'profit',
                    ]);

                    // 一二级提成有对应人才提取对应的金额, 否则赋值为 0 .
                    $input['first_price']  = $car->first_wechat_user_id ? $request->get('first_price') : 0;

                    $input['second_price'] = $car->second_wechat_user_id ? $request->get('second_price') : 0;

                    // 收入 = 利润 - 一级提成 - 二级提成
                    $input['income'] = (float)$input['profit'] - (float)$input['first_price'] - (float)$input['second_price'];

                    // 分佣 = 一级提成 + 二级提成
                    $input['commission'] = (float)$input['first_price'] + (float)$input['second_price'];

                    break;
                case Car::UNDONE_CAR_CODE:
                    $input = $request->only([
                        'car_state',
                    ]);

                    break;
            }

            // 没有填写 情况 处理
            $input['description'] = $input['description'] ? : '';

            $input['admin_user_id'] = $this->auth->id;

            DB::transaction(function () use ($car, $input) {
                // 更新车辆信息
                $car->update($input);

                // 更新代理人金额
                if ($input['car_state'] == Car::DONE_CAR_CODE) {
                    $car->deal_ok_date = date('Y-m-d');
                    $car->save();

                    // 一级代理人
                    if ($car->firstWechatUser) {
                        // 可提现总金额增加
                        $car->firstWechatUser->can_get_price += (float)$input['first_price'];

                        // 总收入增加
                        $car->firstWechatUser->total_price   += (float)$input['first_price'];

                        $car->firstWechatUser->save();
                    }

                    // 二级代理人
                    if ($car->secondWechatUser) {
                        // 可提现总金额增加
                        $car->secondWechatUser->can_get_price += (float)$input['second_price'];

                        // 总收入增加
                        $car->secondWechatUser->total_price   += (float)$input['second_price'];

                        $car->secondWechatUser->save();
                    }
                }
            });

            return redirect(url("admin/car?car_state={$car->car_state}"));
        }
    }

    public function show($id)
    {
        $this->data['page_title'] = '车辆详情';

        $this->data['car'] = $this->car->find($id);

        return view('admin.car.show', $this->data);
    }
}