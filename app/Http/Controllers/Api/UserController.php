<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Libs\Slack;
use App\Models\AttribuiteEmployee;
use App\Models\Noti;
use App\Repositories\EmployeeRepository;
use App\Repositories\UserRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function __construct(private EmployeeRepository $employeeRepo)
    {
        $this->employeeRepo = $employeeRepo;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $employee = $this->employeeRepo->getAllUserByPublic(15);
        return response()->json([
            "status" => "200",
            "payload" => $employee,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public function profile(Request $request){
        $employee = JWTAuth::toUser($request->access_token);
        return $this->responseUser($employee);
    }

    protected function responseUser($profile): JsonResponse
    {
        return response()->json([
                "email" => $profile->email,
                "fullname" => $profile->fullname,
                "avatar" => $profile->getAvatar(),
                "gender" => "$profile->gender",
                "birth_day" => $profile->birth_day,
                "phone" => $profile->phone,
                "employee_code" => $profile->employee_code,
                "branch" => $this->employeeRepo->findBranch($profile->branch_id),
                "id" => $profile->id,
                // 'profile' => $profile,
                'single_word' => $profile->singleWord->count(),
        ], 200);
    }

    protected function changePasssword(Request $request){
        $employee = JWTAuth::toUser($request->access_token);
        if (Hash::check($request->password_old, $employee->password)) {
            $employee->fill([
                'password' => Hash::make($request->password_new)
            ])->save();

            return response()->json([
                'error_code' => 'success',
                'message' => 'Thay ?????i m???t kh???u th??nh c??ng!'
            ], 200);
        }

        return response()->json([
            'error_code' => 'error',
            'message' => 'M???t kh???u c?? kh??ng ????ng!'
        ], 403);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $employee = JWTAuth::toUser($request->access_token);
        $validator = Validator::make($request->all(), [
            'avatar' => 'required|mimes:jpeg,jpg,png,gif|max:10000',
        ],[
            'avatar.required' => 'Vui l??ng ch???n file',
            'avatar.max' => 'File c???a b???n v?????t qu?? 10MB',
            'avatar.mimes' => 'File b???n ch???n kh??ng ph???i file ???nh',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->messages()->first()
            ], 403);
        }

        try {
            $urlImage = $this->storeImage($request, 'avatar');
            $employee->fill([
                'avatar' => $urlImage,
                'type_avatar' => 1
            ])->save();

            return response()->json([
                'error_code' => 'success',
                'message' => 'update avatar th??nh c??ng!',
                'image_links' => $urlImage
            ], 200);
        } catch (\Exception $e) {
            $message = '[' . date('Y-m-d H:i:s') . '] Error message \'' . $e->getMessage() . '\'' . ' in ' . $e->getFile() . ' line ' . $e->getLine();
            \Log::error($message);
            Slack::error($message);
            return response()->json([
                'error_code' => 'error',
                'message' => 'update avatar th???t b???i!'
            ], 403);
        }
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $employee = JWTAuth::toUser($request->access_token);
        $validator = Validator::make($request->all(), [
            'birth_day' => 'required',
            'fullname' => 'required',
            'gender' => 'required',
            'phone' => 'required|regex:/[0-9]{10}/',
        ],[
            'birth_day.required' => 'Ng??y sinh kh??ng ???????c ????? tr???ng',
            'fullname.required' => 'H??? t??n kh??ng ???????c ????? tr???ng',
            'gender.required' => 'Gi???i t??nh kh??ng ???????c ????? tr???ng',
            'phone.required' => 'S??? ??i???n tho???i kh??ng ???????c ????? tr???ng',
            'phone.regex' => 'S??? ??i???n tho???i b???n nh???p kh??ng ????ng',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->messages()->first()
            ], 403);
        }

        $employee->fill([
            'birth_day' => Carbon::create($request->birth_day)->toDateString(),
            'fullname' => $request->fullname,
            'gender' => $request->gender,
            'phone' => $request->phone,
        ])->save();

        return response()->json([
            'error_code' => 'success',
            'message' => 'update th??ng tin th??nh c??ng!',
        ], 200);
    }

    protected function kyc(Request $request): JsonResponse
    {
        $employee = JWTAuth::toUser($request->access_token);
        $Attribuite_Employee = AttribuiteEmployee::OrderBy('created_at', 'desc')->where('employee_id', $employee->id)->select('status')->first();

        if (!empty($Attribuite_Employee)) {
            if ($Attribuite_Employee->status == 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Kh??ng th??? upload t??i li???u khi ??ang ch??? duy???t'
                ], 403);
            }else if ($Attribuite_Employee->status == 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'T??i li???u c???a b???n ???? ???????c duy???t kh??ng th??? t???i l??n t??i li???u m???i'
                ], 403);
            }
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required',
            'file.*' => 'required|mimes:jpeg,jpg,png,pdf,xlx,csv,doc,docx|max:10000',
        ],[
            'file.*.required' => 'Vui l??ng ch???n file',
            'file.*.max' => 'File c???a b???n v?????t qu?? 10MB',
            'file.*.mimes' => 'File b???n ch???n kh??ng ????ng ?????nh d???ng',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->messages()->first()
            ], 403);
        }

        $data = [];
        if ($request->file('file')) {
            try{
                foreach ($request->file('file') as $key => $file) {
                    $name = $employee->employee_code.'_'.Str::random(20).'_'.date('Y-m-d').'.'.$file->extension();
                    $file->storeAs('public/documents', $name);
                    $data[] = $name;
                }
            } catch (\Exception $e) {
                $message = '[' . date('Y-m-d H:i:s') . '] Error message \'' . $e->getMessage() . '\'' . ' in ' . $e->getFile() . ' line ' . $e->getLine();
                \Log::error($message);
                Noti::telegramLog('Upload document', $message);
                return response()->json([
                    'status' => 'error',
                    'message' => 'update th???t b???i!'
                ], 403);
            }
        }
        $raw_data = json_encode($data);

        $document = new AttribuiteEmployee();
        $document->employee_id = $employee->id;
        $document->attribute_id = 1;
        $document->data = "null";
        $document->raw_data = $raw_data;
        $document->save();

        // // return the response
        return response()->json([
            'status' => 'success',
            'message' => 'T???i l??n '.count($data).' t??i li???u th??nh c??ng!',
            'data' => $raw_data
        ], 200);

    }

    protected function storeImage(Request $request, $name = 'image')
    {
        $path = $request->file($name)->store('public/avatars');
        return substr($path, strlen('public/'));
    }

    protected function checkDocument(Request $request){
        $employee = JWTAuth::toUser($request->access_token);
        $status = AttribuiteEmployee::OrderBy('created_at', 'desc')->where('employee_id', $employee->id)->select('status')->first();
        return $status;
    }
}
