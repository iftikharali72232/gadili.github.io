<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Admin\CartController;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;

class OrderController extends Controller
{
    //
    public function manualOrder(Request $request)
    {
        $attrs = $request->validate([
            "description"=> "required",
            "shop_id" => "required|int"
        ]);

        $shop = Shop::find($attrs['shop_id']);

        $order = Order::create([
            "user_id"=> isset(auth()->user()->id) ? auth()->user()->id : 0,
            "seller_id" => $shop->created_by,
            "manual_order" => 1,
            "description" => $request->description,
        ]);

        if($order){
            $userData = User::find($shop->created_by);
            $data = [];
            $data['title'] = 'New Menual Order';
            $data['body'] = 'Your Shop have new manual order';
            $data['device_token'] = $userData->device_token;
            if(!User::sendNotification($data))
            {
                return response([
                    "status"=> "1",
                    "order" => json_decode(json_encode($order), true),
                    "push_notification_status" => User::sendNotification($data),
                ]);
            }
            return response([
                "status"=> "1",
                "order" => json_decode(json_encode($order), true),
                "push_notification_status" => User::sendNotification($data),
            ]);
        } else {
            return response([
                "status"=> "0",
                "message" => "Something Went Wrong",
            ]);
        }
    }
    public function create(Request $request)
    {
        
        $attrs = $request->validate([
            "total"=> "required|numeric",
            "payment_method"=> "required|int",
            "pick_datetime" => "required"
        ]);
        $cart = new CartController();
        // print_r($cart->cartView(1)); exit;
        $cart = $cart->cartView(1);
        if(count($cart) > 0 && isset($cart['cart']) && count($cart['cart']) > 0)
        {
            DB::beginTransaction();
            // try{
    
                $data = [];
                $user = auth()->user();
                
                $data['customer'] = '
                    "name": "'.$user->name.'",
                    "email": "'.$user->email.'",
                    "phone": "'.$user->mobile.'",
                    "street1": "'.$user->address.'"';
        
                $data['total'] = round($attrs['total'], 2);
                $data['description'] = $request->description;
                $data['language'] = "ar";
                $data['shipping_fee'] = 0;
                $data['extra_charges'] = 0;
                $data['extra_discount'] = 0;
        
        
                $order = Order::create([
                    "user_id"=> isset(auth()->user()->id) ? auth()->user()->id : 0,
                    "total"=> round($attrs["total"], 2),
                    "payment_method"=> $attrs["payment_method"],
                    "user_email"=> $user->email,
                    "user_address"=> $user->address,
                    "paid"=> 0,
                    "due" => round($attrs["total"], 2),
                    "tax" => round($cart['total_tax'], 2),
                    "inv_date" => date('Y-m-d'),
                    "discount" => round($cart['total_dicount'], 2),
                    "manual_order" => isset($request->manual_order) ? $request->manual_order : 0,
                    "description" => $request->description,
                    "pickup_date_time" => $attrs['pick_datetime']
    
                ]);
        
                if($order)
                {
                    $order = json_decode(json_encode($order), true);
                    
                        $invoice_items = "";
                        foreach($cart['cart'] as $key => $item)
                        {
                            $data['order_id'] = isset($order['id']) ? $order['id'] : 0;
                            $data['redirect_url'] = url()->to('/success/'.base64_encode($order['id']));
                            if(isset($item['manual_order']) && $item['manual_order'] == 1)
                            {
                                $seller_id = $item['seller_id'];
                                if($key === array_key_last($cart['cart']))
                                {
                                    $invoice_items .= '{
                                        "sku": "'.$item['id'].'",
                                        "description": "'.$item['seller_description'].'",
                                        "url": "",
                                        "unit_cost": '.round($item["total"], 2).',
                                        "quantity": 1,
                                        "net_total": '.round($item['total'], 2).',
                                        "discount_rate": 0,
                                        "discount_amount": 0,
                                        "tax_rate": 0,
                                        "tax_total": 0,
                                        "total": '.round($item['total'], 2).'
                                    }';
                                } else {
                                    $invoice_items .= '{
                                        "sku": "'.$item['id'].'",
                                        "description": "'.$item['seller_description'].'",
                                        "url": "",
                                        "unit_cost": '.round($item["total"], 2).',
                                        "quantity": 1,
                                        "net_total": '.round($item['total'], 2).',
                                        "discount_rate": 0,
                                        "discount_amount": 0,
                                        "tax_rate": 0,
                                        "tax_total": 0,
                                        "total": '.round($item['total'], 2).'
                                    },';
                                }
                                $orderItem = OrderItem::create([
                                    "order_id" => isset($order['id']) ? $order['id'] : 0,
                                    "is_manual" => 1,
                                    "item_quantity" => 1,
                                    "manual_order_id" => $item['id'],
                                    // "item_discount" => round($item['total_discount'], 2), 
                                    "item_total" => $item['total'],
                                    "price" => $item['total'],
                                    "item_description" => $item['seller_description']
                                ]);
                                $orderItem = json_decode(json_encode($orderItem), true);
                                $orderItem['product_data'] = $item;
                                $order['orderItems'][] = $orderItem;
                            } else {
                                $seller_id = $item['created_by'];
                                if($key === array_key_last($cart['cart']))
                                {
                                    $invoice_items .= '{
                                        "sku": "'.$item['id'].'",
                                        "description": "'.$item['p_name'].'",
                                        "url": "",
                                        "unit_cost": '.round($item["price"], 2).',
                                        "quantity": '.$item['quantity'].',
                                        "net_total": '.round($item['total_price'], 2).',
                                        "discount_rate": 0,
                                        "discount_amount": '.round($item['total_discount'], 2).',
                                        "tax_rate": 0,
                                        "tax_total": '.round($item['total_tax'], 2).',
                                        "total": '.round($item['net_total'], 2).'
                                    }';
                                } else {
                                    $invoice_items .= '{
                                        "sku": "'.$item['id'].'",
                                        "description": "'.$item['p_name'].'",
                                        "url": "",
                                        "unit_cost": '.round($item["price"], 2).',
                                        "quantity": '.$item['quantity'].',
                                        "net_total": '.round($item['total_price'], 2).',
                                        "discount_rate": 0,
                                        "discount_amount": '.round($item['total_discount'], 2).',
                                        "tax_rate": 0,
                                        "tax_total": '.round($item['total_tax'], 2).',
                                        "total": '.round($item['net_total'], 2).'
                                    },';
                                }
                                $orderItem = OrderItem::create([
                                    "order_id" => isset($order['id']) ? $order['id'] : 0,
                                    "product_id" => $item['id'],
                                    "item_quantity" => $item['quantity'],
                                    "item_tax" => round($item['total_tax'], 2),
                                    "item_discount" => round($item['total_discount'], 2), 
                                    "item_total" => $item['net_total'],
                                    "price" => $item['price'],
                                    "item_description" => $item['p_name']
                                ]);
                                $orderItem = json_decode(json_encode($orderItem), true);
                                $orderItem['product_data'] = $item;
                                $order['orderItems'][] = $orderItem;

                            }
        
                        }
                        $data['invoice_items'] = $invoice_items;
                        $pm = PaymentMethod::find($attrs["payment_method"]);
                        // print_r($pm); exit;
                        if($pm->slug == "click_pay")
                        {
                            $data['profile_key'] = $pm->public_key;
                            $data['secret_key'] = $pm->secret_key;
                            $res = Order::clickPay($data);
                            $res = json_decode($res, true);
                            $res['id'] = $order['id'];
                            if(isset($res['invoice_id']))
                            {
                                DB::table("orders")->where("id", "=", $order['id'])->update([
                                    'invoice_id' => $res['invoice_id'],
                                    'seller_id' =>$seller_id
                                ]);
                                DB::select("DELETE FROM carts WHERE user_id=".auth()->user()->id);
                                DB::commit();
                                return response([
                                    'status' => "1",
                                    "data" => $res,
                                    "push_notification_status" => 'Push notification send successfully',
                                ]);
                            } else {

                                return response([
                                    'status' => "0",
                                    "message" => "Payment success faild."
                                ]);
                            }
                            
                        } else if($pm->slug == "COD"){
                            DB::table("orders")->where("id", "=", $order['id'])->update([
                                'seller_id' =>$seller_id
                            ]);
                            DB::select("DELETE FROM carts WHERE user_id=".auth()->user()->id);
                            DB::commit();
                            $userData = User::find($seller_id);
                            $data = [];
                            $data['title'] = 'New Order';
                            $data['body'] = 'Your Shop have new order';
                            $data['device_token'] = $userData->device_token;
                            $data['order_id'] = $order['id'];
                            $notification = new Notification();
                            $notification->user_id = $seller_id; // Assuming the user is authenticated
                            $notification->message = 'Your Shop have new order';
                            $notification->description = $request->description;
                            $notification->order_id = $order['id'];
                            $notification->page = 'orders';
                            $notification->save();

                            $notification1 = new Notification();
                            $notification1->user_id = auth()->user()->id; // Assuming the user is authenticated
                            $notification1->message = 'Your order placed successfully';
                            $notification1->description = $request->description;
                            $notification1->order_id = $order['id'];
                            $notification1->page = 'orders';
                            $notification1->save();
                            
                            return response([
                                "status" => "1",
                                "data" => $order,
                                'push_notification_status' => User::sendNotification($data)
                            ]);
                            
                        }
                    
                } else {
                    return response([
                        "status" => "0",
                        "message"=> "Something went wrong"
                    ]);
                }

        } else {
            {
                return response([
                    "success" => "0",
                    "message" => "Please add atleast one item."
                ]);
            }
        }
    }

    public function orderList()
    {
        $user = auth()->user();

        $recentOrders = $user->orders()
             ->with(['orderItems' => function ($query) {
                 $query->select('id', 'order_id', 'product_id', 'item_quantity'); // Limit columns for order items
             }, 'orderItems.product' => function ($query) {
                 $query->select('id', 'p_name', 'price', 'images'); // Limit columns for products
             }])
             ->select('id', 'total', 'order_status','seller_id', 'user_id', 'payment_method','manual_order','pickup_date_time') // Limit columns for orders
             ->orderByDesc('created_at')
             ->get();
         // print_r($orders);
         // $recentOrders = Order::where('user_id', $user->id)->get(['id', 'total', 'order_status','seller_id', 'user_id', 'payment_method','manual_order']);
         if(count($recentOrders) > 0)
         {
             foreach($recentOrders as $key => $order)
             {
                 $shop = Shop::where('created_by', $order->seller_id)->get(['name', 'logo', 'galary_images']);
                 $recentOrders[$key]['shop'] = isset($shop[0]) ? $shop[0] : "";
             }
         }
         return response()->json([
             'status' => 1,
             'image_base_url' => asset('images/'),
             'orders' => $recentOrders
         ]);
    }

    public function orderListByUserId()
    {
        $user = auth()->user();
        if($user->user_type == 1)
        {
            $orders = Order::with(['orderItems' => function ($query) {
                            $query->select('id', 'order_id', 'product_id', 'item_quantity'); // Limit columns for order items
                        }, 'orderItems.product' => function ($query) {
                            $query->select('id', 'p_name', 'price', 'images', 'category_id'); // Limit columns for products
                        },
                        'orderItems.product.category' => function ($query) {
                            $query->select('id', 'name'); // Assuming the category has these fields
                        },
                        'user'
                        ])// Limit columns for orders
                        ->where("seller_id", "=", auth()->user()->id)
                        ->orderByDesc('created_at')->paginate(30);
            
            return response([
                'status' => "1",
                "orders" =>json_decode(json_encode($orders), true)
            ]);
        } else {
            $orders = Order::with(['orderItems' => function ($query) {
                            $query->select('id', 'order_id', 'product_id', 'item_quantity'); // Limit columns for order items
                        }, 'orderItems.product' => function ($query) {
                            $query->select('id', 'p_name', 'price', 'images', 'category_id'); // Limit columns for products
                        },
                        'orderItems.product.category' => function ($query) {
                            $query->select('id', 'name'); // Assuming the category has these fields
                        }
                        ])
                        ->select('id', 'total', 'order_status','seller_id', 'user_id', 'payment_method','manual_order','pickup_date_time') // Limit columns for orders
                        ->where("user_id", "=", auth()->user()->id)
                        ->orderByDesc('created_at')->paginate(30);
            
            return response([
                'status' => "1",
                "orders" =>json_decode(json_encode($orders), true)
            ]);

        }
    }

    public function get($id)
    {
       $order = Order::find($id);
    
       $orderItems = json_decode(json_encode(DB::table("order_items")->WHERE("order_id", $order->id)->get()), true);
       if(count($orderItems) > 0) {
            foreach($orderItems as $key => $orderItem) {
                $product = Product::find($orderItem['product_id']);
                $orderItems[$key]['product_data'] = json_decode(json_encode($product), true);
            }
        }
       $order->orderItems = $orderItems;

        return response([
            "status"=> "1",
            "orders"=> json_decode(json_encode($order), true)
        ]);
    }

    public function orderStatus($order_id)
    {
        $id = intval(base64_decode($order_id));
        // echo $order_id;
        $order = Order::find($id);
        if(!isset($order->id))
        {
            $order = Order::find($order_id);
        }
        $pm = PaymentMethod::find($order->payment_method);
        if($pm->slug == "click_pay"){
            $data['secret_key'] = $pm->secret_key;
            $data['invoice_id'] = $order->invoice_id;
            $status = Order::clickPayOrderStatus($data);
            $status = json_decode($status, true);
            if(isset($status['invoice_status']) && $status['invoice_status'] == "paid")
            {
                // print_r($status); exit;
                DB::table("orders")->where("id", "=", $order->id)->update([
                    "order_status" => 2,
                    "paid" => $order->total,
                    "due" => 0
                ]);
                return response([
                    'status' => "1",
                    "data" => $status,
                    "message" => "Order placed successfully"
                ]);
            } else {
                return response([
                    'status' => "1",
                    'data' => $status,
                    "message" => "Order placed successfully"
                ]);
            }

        } else if($pm->slug == "COD")
        {
            DB::table("orders")->where("id", "=", $order_id)->update([
                "order_status" => 2,
                "paid" => $order->total,
                "due" => 0
            ]);
            return response([
                'status' => "1",
                "data" => ["payment_method" => "Cash on delivery"],
                "message" => "Order placed successfully"
            ]);
        }
    }

    public function notifiOrders()
    {
        $orders = DB::table('orders')->where('user_id', auth()->user()->id)->where('is_read', 0)->get();
        // print_r($orders);
        return response([
            'status' => 1,
            'notifications' => json_decode(json_encode($orders), true)
        ]);
    }
    public function readNotification($id)
    {
        DB::table('orders')->where('id', $id)->update([
            'is_read' => 1
        ]);
        return response([
            'status' => 1,
            'message' => 'Message reads'
        ]);
    }

    public function recentOrders()
    {
        $user = auth()->user();

       $recentOrders = $user->orders()
            ->with(['orderItems' => function ($query) {
                $query->select('id', 'order_id', 'product_id', 'item_quantity'); // Limit columns for order items
            }, 'orderItems.product' => function ($query) {
                $query->select('id', 'p_name', 'price', 'images'); // Limit columns for products
            }])
            ->select('id', 'total', 'order_status','seller_id', 'user_id', 'payment_method','manual_order','pickup_date_time') // Limit columns for orders
            ->orderByDesc('created_at')
            ->take(10)
            ->get();
        // print_r($orders);
        // $recentOrders = Order::where('user_id', $user->id)->get(['id', 'total', 'order_status','seller_id', 'user_id', 'payment_method','manual_order']);
        if(count($recentOrders) > 0)
        {
            foreach($recentOrders as $key => $order)
            {
                $shop = Shop::where('created_by', $order->seller_id)->get(['name', 'logo', 'galary_images']);
                 $recentOrders[$key]['shop'] = isset($shop[0]) ? $shop[0] : "";
            }
        }
        return response()->json([
            'status' => 1,
            'image_base_url' => asset('images/'),
            'orders' => $recentOrders
        ]);
    }

    public function sellerTotalOrders()
    {
        $user = auth()->user();

        // $orders = DB::table('orders')->where('seller_id', $user->id)->where('manual_order', 0)->orderByDesc('id')->paginate(10);

        $total = Order::where('seller_id', $user->id)->where('manual_order', 0)->where('order_status', 1)->sum('paid');

        $count = Order::where('seller_id', $user->id)->where('manual_order', 0)->count();

        $pcount = Product::where('created_by', $user->id)->count();

        // $manualOrderCounter = Order::where('seller_id', $user->id)->where('manual_order', 1)->where('is_response', 0)->count();

        // $manual_orders = DB::table('orders')->where('seller_id', $user->id)->where('manual_order', 1)->where('is_response', 0)->orderByDesc('id')->paginate(10);
        // print_r($total); print_r($orders);
        return response([
            'status' => 1,
            'totalSum' => $total,
            'OrderCounter' => $count,
            'total_products' => $pcount
            // 'manualOrderCounter' => $manualOrderCounter,
            // 'manual_orders' => json_decode(json_encode($manual_orders), true),
            // 'orders' => json_decode(json_encode($orders), true)
        ]);
    }

    public function manualOrderSellers()
    {
        $user = auth()->user();

        // $orders = DB::table('orders')->where('seller_id', $user->id)->where('manual_order', 0)->orderByDesc('id')->paginate(10);

        // $total = Order::where('seller_id', $user->id)->where('manual_order', 0)->where('order_status', 1)->sum('paid');

        // $count = Order::where('seller_id', $user->id)->where('manual_order', 0)->count();

        $manualOrderCounter = Order::where('seller_id', $user->id)->where('manual_order', 1)->where('is_response', 0)->count();

        $manual_orders = DB::table('orders')->where('seller_id', $user->id)->where('manual_order', 1)->where('is_response', 0)->orderByDesc('id')->paginate(10);
        // print_r($total); print_r($orders);
        return response([
            'status' => 1,
            // 'totalSum' => $total,
            // 'OrderCounter' => $count,
            'manualOrderCounter' => $manualOrderCounter,
            'manual_orders' => json_decode(json_encode($manual_orders), true),
            // 'orders' => json_decode(json_encode($orders), true)
        ]);
    }
    public function changeOrderStatus($status, Request $request)
    {
        $attrs = $request->validate(['order_id' => 'required|int']);
        $order = DB::table('orders')->where('id', $attrs['order_id'])->update([
            'order_status' => $status
        ]);
        
        return response([
            'status' => 1,
            'orders' => 'Order status changed successfully'
        ]);
    }

    public function recentOrderItems()
    {
        $user = auth()->user();

        $recentOrders = $user->orders()->where('manual_order', 0)
            ->orderByDesc('created_at')
            ->take(10) // Get the 5 most recent orders
            ->get();

        $products = collect();

        foreach ($recentOrders as $order) {
            $orderItems = $order->orderItems;
            
            foreach ($orderItems as $orderItem) {
                $product = $orderItem->product;
                $products->push($product);
            }
        }

        // print_r($products);
        return response([
            'status'=> 1,
            'products' => json_decode(json_encode($products), true)
        ]);

    }

    public function manualOrderProcess(Request $request)
    {
        $data = $request->validate([
            'amount' => "required",
            'description' => 'required',
            'order_id' => 'required'
        ]);

        $order = DB::table('orders')->where('id', $data['order_id'])->update([
            'total' => $data['amount'],
            'seller_description' => $data['description'],
            'is_response' => 1
        ]);
        if($order) {
            $order = Order::find($data['order_id']);
            $notification = new Notification();
                $notification->user_id = $order->user_id; // Assuming the user is authenticated
                $notification->message = 'Your manual order processed successfully';
                $notification->description = $request->description;
                $notification->order_id = $request->order_id;
                $notification->page = 'menual_orders';
                $notification->save();

            $userData = User::find($order->user_id);
                                $data = [];
                                $data['title'] = 'Manual Order Response';
                                $data['body'] = 'Your manual order ready to purchase';
                                $data['device_token'] = $userData->device_token;
                                $data['order_id'] = $order->id;
            return response([
                'status' => 1,
                'msg' => 'success',
                "push_notification_status" => User::sendNotification($data)
            ]);
        }else {
            return response([
                'status' => 0,
                'msg' => 'failed'
            ]);
        }
    }

    public function buyerManualOrderNotify()
    {
        $user = auth()->user();

        // $orders = DB::table('orders')->where('seller_id', $user->id)->where('manual_order', 0)->orderByDesc('id')->paginate(10);

        // $total = Order::where('seller_id', $user->id)->where('manual_order', 0)->where('order_status', 1)->sum('paid');

        // $count = Order::where('seller_id', $user->id)->where('manual_order', 0)->count();

        $manualOrderCounter = Order::where('user_id', $user->id)->where('manual_order', 1)->where('is_response', 1)->where('is_read', 0)->count();

        $manual_orders = DB::table('orders')->where('user_id', $user->id)->where('manual_order', 1)->where('is_response', 1)->where('is_read', 0)->orderByDesc('id')->paginate(10);
        // print_r($total); print_r($orders);
        return response([
            'status' => 1,
            // 'totalSum' => $total,
            // 'OrderCounter' => $count,
            'manualOrderCounter' => $manualOrderCounter,
            'manual_orders' => json_decode(json_encode($manual_orders), true),
            // 'orders' => json_decode(json_encode($orders), true)
        ]);
    }

    public function allOrderProducts()
    {
        $sellerProducts = Order::where('seller_id', auth()->user()->id)
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->select(
                'products.id',
                'products.p_name',
                DB::raw('SUM(order_items.item_quantity) as total_quantity'),
                DB::raw('COUNT(products.id) as product_count'),
                DB::raw('SUM(order_items.item_total) as total_amount')
            )
            ->groupBy('products.id', 'products.p_name')
            ->get();

            return response([
                'status' => 1,
                'items' => response()->json($sellerProducts)
            ]);
    }

    public function getOrders($key,$sellerId, $filter, $startDate = null, $endDate = null, $perPage = 10)
    {
        $query = Order::where($key, $sellerId);

        if ($filter === 'this_month') {
            $query->whereYear('created_at', '=', now()->year)
                ->whereMonth('created_at', '=', now()->month);
        } elseif ($filter === 'this_year') {
            $query->whereYear('created_at', '=', now()->year);
        } elseif ($filter === 'date_range' && $startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }
        // You can add more conditions based on your requirements, such as for 'all' filter.

        // Order by created_at in descending order
        $query->orderBy('created_at', 'desc');
        // Execute the query and paginate the results
        $orders = $query->paginate($perPage);

        return $orders;
    }

    public function getUserOrders(Request $req)
    {
        $data = $req->validate([
            'filter' => 'required',
            'perPage' => 'required|int'
        ]);
        if($data['filter'] == 'date_range')
        {
            $data1 = $req->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date'
            ]);
            $user = auth()->user();
            if($user->user_type == 1)
            {
                $ordersInRange = $this->getOrders('seller_id', $user->id, 'date_range', $data1['start_date'], $data1['end_date'], $data['perPage']);

            } else if($user->user_type == 2)
            {
                $ordersInRange = $this->getOrders('user_id', $user->id, 'date_range', $data1['start_date'], $data1['end_date'], $data['perPage']);
            }

            return response()->json(['orders' => $ordersInRange]);
        }

        if($data['filter'] == 'this_month')
        {
            $user = auth()->user();
            if($user->user_type == 1)
            {
                $ordersInRange = $this->getOrders('seller_id', $user->id, 'this_month', null, null, $data['perPage']);

            } else if($user->user_type == 2)
            {
                $ordersInRange = $this->getOrders('user_id', $user->id, 'this_month', null, null, $data['perPage']);
            }

            return response()->json(['orders' => $ordersInRange]);
        }

        if($data['filter'] == 'this_year')
        {
            $user = auth()->user();
            if($user->user_type == 1)
            {
                $ordersInRange = $this->getOrders('seller_id', $user->id, 'this_year', null, null, $data['perPage']);

            } else if($user->user_type == 2)
            {
                $ordersInRange = $this->getOrders('user_id', $user->id, 'this_year', null, null, $data['perPage']);
            }

            return response()->json(['orders' => $ordersInRange]);
        }

        if($data['filter'] == 'all')
        {
            $user = auth()->user();
            if($user->user_type == 1)
            {
                $ordersInRange = $this->getOrders('seller_id', $user->id, 'all', null, null, $data['perPage']);

            } else if($user->user_type == 2)
            {
                $ordersInRange = $this->getOrders('user_id', $user->id, 'all', null, null, $data['perPage']);
            }

            return response()->json(['orders' => $ordersInRange]);
        }


    }
}
