<?php

namespace App\Http\Controllers;

use App\Models\Noti;
use App\Repositories\EmployeeRepository;
use App\Repositories\SingleTypeRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Repositories\RequestRepository;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use App\Models\Request as ModelRequest;
use App\Repositories\EmployeeLeavePermissionRepository;
use App\Repositories\NotifycationRepository;
use App\Repositories\TimekeepRepository;
use App\Repositories\WorkScheduleRepository;
use App\Service\TimesheetService;
use Illuminate\Support\Facades\Notification;


class ApplicationController extends Controller
{
    public function __construct(
        private SingleTypeRepository $singleTypeRepo,
        private EmployeeRepository $employeeRepo,
        private RequestRepository $requestRepo,
        private TimesheetService $timesheetService,
        private EmployeeLeavePermissionRepository $employeeLeavePermissionRepo,
        private NotifycationRepository $notifycationRepo,
        private TimekeepRepository $timekeepRepo,
        private WorkScheduleRepository $workScheduleRepo
    )
    {
        //
    }

    public function index(Request $request)
    {
        $take = 1;
        $statusPending  = array(config('request.status.processing'), config('request.status.leader_accepted'));
        $statusAccepted = array(config('request.status.accepted'), config('request.status.leader_accepted'));
        $statusUnapproved = config('request.status.unapproved');
        $options = [
            'with' => [
                'employee' => function($query) {
                    $query->select('fullname', 'id', 'position_id');
                },
                'singleType' => function ($query) {
                    $query->select('id', 'required_leader', 'type', 'name');
                },
                'singleType.approvers.employee' => function($query) {
                    $query->select('fullname', 'id', 'type_avatar', 'avatar');
                },
                'employee.position.department',
                'requestDetail',
            ],
            'approver_employee' => Auth::user(),
            'keyword' => $request->keyword,
            'request_id' => $request->request_id,
            'employee_id' => $request->employee_id,
            'type' => $request->type
        ];
        $requestProcess = $this->requestRepo->formatDataPaginate($take, [...$options, 'statues' => $statusPending, 'need_browser' => true, 'page_name' => 'process_page']);
        $requestAccepted = $this->requestRepo->formatDataPaginate($take, [...$options, 'statues' => $statusAccepted, 'accepted' => true, 'page_name' => 'accep_page']);
        $requestUnapproved = $this->requestRepo->formatDataPaginate($take, [...$options, 'status' => $statusUnapproved, 'page_name' => 'unapp_page']);
        return view('admin.application.request.request', compact('requestProcess', 'requestUnapproved', 'requestAccepted'));
    }

    public function responseRequestData(Request $request): JsonResponse
    {
        $take = 1;
        $statusPending  = array(config('request.status.processing'), config('request.status.leader_accepted'));
        $statusAccepted = array(config('request.status.accepted'), config('request.status.leader_accepted'));
        $statusUnapproved = config('request.status.unapproved');
        $options = [
            'with' => [
                'employee' => function($query) {
                    $query->select('fullname', 'id', 'position_id');
                },
                'singleType' => function ($query) {
                    $query->select('id', 'required_leader', 'type', 'name');
                },
                'singleType.approvers.employee' => function($query) {
                    $query->select('fullname', 'id', 'type_avatar', 'avatar');
                },
                'employee.position.department',
                'requestDetail',
            ],
            'approver_employee' => Auth::user(),
            'keyword' => $request->keyword,
            'request_id' => $request->request_id,
            'employee_id' => $request->employee_id,
            'type' => $request->type
        ];
        $requestProcess = $this->requestRepo->formatDataPaginate($take, [...$options, 'statues' => $statusPending, 'need_browser' => true, 'page_name' => 'process_page']);
        $requestAccepted = $this->requestRepo->formatDataPaginate($take, [...$options, 'statues' => $statusAccepted, 'accepted' => true, 'page_name' => 'accep_page']);
        $requestUnapproved = $this->requestRepo->formatDataPaginate($take, [...$options, 'status' => $statusUnapproved, 'page_name' => 'unapp_page']);
        return response()->json([
            "process" => $requestProcess,
            "accepted" => $requestAccepted,
            "unapproved" => $requestUnapproved,
        ]);
    }

    public function requestDetail(Request $request, $requestId) {

        $requestData = ModelRequest::find($requestId);
        $approvers = $this->requestRepo->getApprover($requestData);
        if (!$requestData) {
            abort(404);
        }
        $requestDetail = $requestData->requestDetail;
        $quitWorkFromAt = Carbon::parse($requestDetail->quit_work_from_at);
        $quitWorkToAt = Carbon::parse($requestDetail->quit_work_to_at);
        $canViewApprover = false;
        $currentAdmin = Auth::user();
        $canViewApprover = $this->requestRepo->canViewApproverRequest($requestData, $currentAdmin, $approvers);
        $leaveDay = $this->timesheetService->getDifferentDay($quitWorkFromAt, $quitWorkToAt);

        $requestType = $requestData?->singleType?->type;
        if ($requestType == config('singletype.type.leave_work')) {
            return view('admin.application.request.detail-leave-work', compact('requestData', 'requestId', 'approvers', 'leaveDay', 'canViewApprover'));
        } else if ($requestType == config('singletype.type.edit_work')) {
            $options = [
                "date" => $requestDetail->quit_work_to_at->format("Y-m-d"),
                "employee_id" => $requestData->employee_id
            ];
            $checkinOld = null;
            $checkoutOld = null;
            $newLeaveDay = 0;
            $timekeep = $this->timekeepRepo->query($options)->orderBy('id', 'desc')->first();
            if ($timekeep) {
                $checkinOld = $timekeep->timekeepDetail()?->min('checkin_at') ?: null;
                $checkoutOld = $timekeep->timekeepDetail()->max('checkin_at') ?: null;
                if ($checkinOld) {
                    $checkinOld = Carbon::parse($checkinOld)?->format("H:i");
                }

                if ($checkoutOld) {
                    $checkoutOld = Carbon::parse($checkoutOld)?->format("H:i");
                }

                if ($checkoutOld == $checkinOld) {
                    $checkoutOld = null;
                }
                $workTime = $this->timekeepRepo->getWorkTime($requestData->employee, $requestDetail->quit_work_from_at, $requestDetail->quit_work_to_at);
                $newLeaveDay = $workTime['worktime'];
            }
            return view('admin.application.request.detail-edit-work', compact('requestData', 'requestId', 'approvers', 'leaveDay', 'canViewApprover', 'timekeep', 'checkinOld', 'checkoutOld', 'newLeaveDay'));
        } else if ($requestType == config('singletype.type.ot')) {
            return view('admin.application.request.detail-ot', compact('requestData', 'requestId', 'approvers', 'leaveDay', 'canViewApprover'));
        }
        abort(404);
    }

    public function nestView()
    {
        $options = [
            'with' => 'approvers.employee'
        ];
        $take = 10;
        $singleTypes = $this->singleTypeRepo->query($options)->paginate($take);
        return view('admin.application.singleType.viewnest', compact('singleTypes'));
    }

    public function createSingleType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'type' => [
                'required',
                Rule::in(config('singletype.type'))
            ],
            'employee_id' => 'required|array',
            'regulation' => 'required',
            'description' => 'required',
        ], [
            'name.required' => 'T??n lo???i ????n kh??ng ???????c ????? tr???ng',
            'name.max' => 'T??n lo???i lo???i ????n qu?? d??i',
            'type.required' => 'Lo???i m???u ????n kh??ng ???????c ????? tr???ng',
            'type.in' => 'Kh??ng x??c ?????nh ???????c m???u ????n n??y',
            'employee_id.required' => 'Ng?????i duy???t ????n kh??ng ???????c ????? tr???ng',
            'employee_id.array' => '?????nh d???ng kh??ng h???p l???',
            'regulation.required' => 'Quy tr??nh duy???t ????n kh??ng ???????c ????? tr???ng',
            'description.required' => 'M?? t??? kh??ng ???????c ????? tr???ng'
        ]);

        if ($validator->fails()) {
            return redirect()->back()->with('message.error', $validator->messages()->first())->withInput();
        }

        $options = $request->all();

        if ($request->required_leader) {
            $options['required_leader'] = $request->required_leader;
        } else {
            unset($options['required_leader']);
        }

        try {
            DB::beginTransaction();
            $result = $this->singleTypeRepo->createWithApprover($options);
            DB::commit();
            return redirect()->route('application-nest-view')->with('message.success', 'Th??m lo???i ????n th??nh c??ng')->with('id_new', $result->id);
        } catch (\Exception $e) {
            $message = '[' . date('Y-m-d H:i:s') . '] Error message \'' . $e->getMessage() . '\'' . ' in ' . $e->getFile() . ' line ' . $e->getLine();
            Log::error($message);
            DB::rollBack();
            Noti::telegramLog('Create Single Type', $message);
            return redirect()->back()->with('message.error', 'Th??m th???t b???i vui l??ng li??n h??? qu???n tr??? vi??n !')->withInput();
        }
    }

    public function showFormCreateSingleType(Request $request)
    {
        $rootDepartmentId = config('department.root.hr');
        $employees = $this->employeeRepo->getEmployeeByDepartmentIds(array($rootDepartmentId));

        return view('admin.application.singleType.nestCreate', compact('employees'));
    }

    public function changeStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            'status' => Rule::in(config('singletype.status')),
        ], [
            'id.required' => 'Kh??ng t??m th???y lo???i ????n n??y',
            'status.in' => 'Tr???ng th??i kh??ng h???p l???',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->messages()->first()
            ], 404);
        }

        $result = $this->singleTypeRepo->changeStatus($request->id, $request->status);
        if ($result) {
            return response()->json([
                'success' => true
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'C???p nh???t tr???ng th??i th???t b???i'
        ], 404);
    }

    public function ajaxAcceptRequest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            'status' => ['required', Rule::in(config('request_approve_history.status'))]
        ], [
            "id.require" => "Kh??ng t??m th???y lo???i ????n n??y",
            "status.require" => "Tr???ng th??i kh??ng ???????c ????? tr???ng",
            "status.in" => "Tr???ng th??i kh??ng ????n kh??ng h???p l???"
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => "validate_failed",
                "message" => $validator->messages()->first()
            ], 442);
        }

        if ($request->status == config('request_approve_history.status.unapproved')){
            if (empty($request->reason)) {
                return response()->json([
                    "status" => "validate_failed",
                    "message" => "L?? do kh??ng ???????c ????? tr???ng !"
                ], 442);
            }
        }

        $modelRequest = $this->requestRepo->find($request->id);
        $currentAdmin = Auth::user();
        if (!$modelRequest) {
            return response()->json([
                "status" => "failed",
                "message" => "Kh??ng t??m th???y ????n n??y"
            ], 442);
        }
        DB::beginTransaction();
        try {
            $data = [
                'status' => $request->status,
                'employee_id' => $currentAdmin->id
            ];
            if ($request->status == config('request_approve_history.status.unapproved')) {
                $data['reason'] = $request->reason;
            }

            $result = $this->requestRepo->handleApprove($data, $modelRequest);
            if ($result) {
                if ($result->status == config('request.status.accepted')) {
                    // L??u th??ng tin cho ????n ngh??? ph??p
                    if ($result->singleType->type == config("singletype.type.leave_work")) {
                        $this->employeeLeavePermissionRepo->enforcementRequest($result);
                    } else if ($result->singleType->type == config("singletype.type.edit_work")) {
                        $requestDetail = $result->requestDetail;
                        $workTime = $this->timekeepRepo->getWorkTime($result->employee, $requestDetail->quit_work_from_at, $requestDetail->quit_work_to_at);
                        $options = [
                            "date" => $requestDetail->quit_work_to_at->format("Y-m-d"),
                            "employee_id" => $result->employee_id
                        ];
                        $timekeep = $this->timekeepRepo->query($options)->orderBy('id', 'desc')->first();

                        $minTimekeepDetail = $timekeep->timekeepDetail()->orderBy('checkin_at', "asc")->first();
                        $minTimekeepDetail->checkin_at = $requestDetail->quit_work_from_at;
                        $minTimekeepDetail->save();

                        $maxTimekeepDetail = $timekeep->timekeepDetail()->orderBy('checkin_at', "desc")->first();
                        $maxTimekeepDetail->checkin_at = $requestDetail->quit_work_to_at;
                        $maxTimekeepDetail->save();

                        $timekeep->worktime = $workTime['worktime'];
                        $timekeep->minute_late = $workTime['minute_early'];
                        $timekeep->minute_early = $workTime['minute_late'];
                        $timekeep->save();
                    }
                }

                // G???i th??ng b??o
                $content = $result->singleType->name . " c???a b???n ???? ???????c duy???t b???i " . $currentAdmin->fullname;
                $options = [
                    'title' => "????n c???a b???n ???? ???????c ph?? duy???t",
                    'content' => $content,
                    'request_domain' => config('notification.domain.FE'),
                    'request_type' => config('notification.type.personal'),
                    'employee_ids' => array($result->employee_id),
                    'link' => '/more/don-tu-cua-ban',
                ];

                if ($result->status == config('request.status.unapproved')) {
                    $options['title'] = "????n c???a b???n ???? b??? t??? ch???i";
                    $options['content'] = $result->singleType->name . " c???a b???n ???? b??? t??? ch???i b???i " . $currentAdmin->fullname;
                }
                $this->notifycationRepo->pushNotifications($options);

                // G???i realtime
                $fcmTokens = array($result->employee->fcm_token ?? null);
                $dataNoti = json_encode(["title" => $options['title'], "content" => $options['content']]);
                Notification::send(null, new \App\Notifications\SendPushNotification("noti_request_done", $dataNoti, $fcmTokens));

                DB::commit();
                return response()->json([
                    "status" => "success",
                    "message" => "???? duy???t ????n th??nh c??ng"
                ]);
            }
        } catch (\Exception $e) {
            $message = '[' . date('Y-m-d H:i:s') . '] Error message \'' . $e->getMessage() . '\'' . ' in ' . $e->getFile() . ' line ' . $e->getLine();
            Log::error($message);
            DB::rollBack();
            Noti::telegramLog('Approver Request', $message);
        }

        return response()->json([
            "status" => "failed",
            "message" => "Kh??ng th??? duy???t ????n v??o l??c n??y !"
        ], 442);
    }
}
