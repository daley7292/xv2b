<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->has('id')) {
            $id = $request->input('id');
            $notice = Notice::where('id', $id)
                ->where('show', 1)
                ->first();
    
            if (!$notice) {
                return response([
                    'message' => 'Notice not found'
                ], 404);
            }
    
            return response([
                'data' => $notice
            ]);
        }
    
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 5);
    
        $pageSize = min(max($pageSize, 1), 100);
    
        $model = Notice::orderBy('created_at', 'DESC')
            ->where('show', 1);
    
        $total = $model->count();
        $res = $model->forPage($current, $pageSize)->get();
    
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function getPopMessage(Request $request)
    {
        $windowsType = $request->input('windows_type');
        if (!$windowsType) {
            return response(['error' => 'windows_type is required'], 400);
        }
        $res = Notice::orderBy('created_at', 'DESC')
            ->where('show', 1)
            ->where('windows_type', $windowsType)
            ->whereJsonContains('tags', '弹窗')
            ->get();
        return response([
            'data' => $res
        ]);
    }
}