<?php

namespace AiFace\WebSocket\Http\Controllers;

use AiFace\WebSocket\Facades\AiFace;
use AiFace\WebSocket\Models\AiFaceAttendanceLog;
use AiFace\WebSocket\Models\AiFaceDevice;
use Illuminate\Routing\Controller;

class AiFaceDashboardController extends Controller
{
    public function index()
    {
        $onlineDevices = AiFace::getOnlineDevices();
        $dbDevices = AiFaceDevice::all();
        $recentLogs = AiFaceAttendanceLog::orderBy('punch_time', 'desc')->limit(20)->get();

        return view('aiface::dashboard', [
            'onlineDevices' => $onlineDevices,
            'dbDevices' => $dbDevices,
            'recentLogs' => $recentLogs,
            'serverPort' => config('aiface.server.port', 7788),
            'serverPath' => config('aiface.server.path', '/pub/chat'),
        ]);
    }
}
