<?php
namespace App\Http\Controllers\Admin;

use App\Admin\Access;
use App\Admin\Catalog;
use App\Admin\Workbench;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WorkbenchController extends Controller
{
    private function authorizeUsers(Request $request): void {
        Access::authorize($request->user());
        abort_unless(array_intersect(Access::scopes($request->user()), ['Accounts','Moderation','Access restrictions','Economy','Avatar','Messages','Staff','Audit']),403,'User management access required.');
    }
    private function navbar(Request $request): string {
        return app(\App\View\PageData::class)->adminNavbar();
    }
    private const USER_SECTIONS = [
        'overview'=>['Overview', null], 'account'=>['Account', 'Accounts'],
        'moderation'=>['Moderation history', 'Moderation'], 'notes'=>['Moderator notes', 'Moderation'],
        'restrictions'=>['Restrictions', 'Access restrictions'], 'economy'=>['Transactions', 'Economy'],
        'avatar'=>['Avatar', 'Avatar'], 'messages'=>['Staff message', 'Messages'],
        'permissions'=>['Staff permissions', 'Staff'], 'changes'=>['Admin history', 'Audit'],
    ];
    private function activeBans($query) {
        return $query->where('reactivated',Workbench::flag('bans','reactivated',false))
            ->where('undone',Workbench::flag('bans','undone',false))
            ->where(fn($q)=>$q->where('perm',Workbench::flag('bans','perm',true))->orWhere('expire','>',now()));
    }
    public function users(Request $request) {
        $this->authorizeUsers($request);
        $request->validate(['q'=>'nullable|string|max:80','sort'=>'nullable|in:id,username,created,lastlogin','direction'=>'nullable|in:asc,desc','state'=>'nullable|in:all,active,banned','page'=>'nullable|integer|min:1|max:10000']);
        $q=trim((string)$request->input('q',''));
        $sort=$request->input('sort','id');$direction=$request->input('direction','desc');$state=$request->input('state','all');
        $users=DB::connection('finobe')->table('users')->select('id','username','status','created','lastlogin');
        if($q!=='')$users->where(function($query)use($q){
            $query->whereRaw("username LIKE ? ESCAPE '!'",['%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%']);
            if(ctype_digit($q))$query->orWhere('id',(int)$q);
        });
        $active=$this->activeBans(DB::connection('finobe')->table('bans')->selectRaw('1')->whereColumn('bans.username','users.username'));
        if($state==='banned')$users->whereExists($active);
        if($state==='active')$users->whereNotExists($active);
        $users->selectSub((clone $active)->limit(1),'is_banned');
        $users->orderBy($sort,$direction);if($sort!=='id')$users->orderBy('id',$direction);
        return view('admin.users',['actor'=>$request->user(),'navbarHtml'=>$this->navbar($request),'users'=>$users->paginate(30)->withQueryString(),'query'=>$q,'sort'=>$sort,'direction'=>$direction,'state'=>$state]);
    }
    private function userPageData(Request $request,string $id,string $section): array {
        $this->authorizeUsers($request);
        abort_unless(ctype_digit($id) && isset(self::USER_SECTIONS[$section]),404,'User page not found.');
        $scopes=Access::scopes($request->user());
        [$title,$scope]=self::USER_SECTIONS[$section];
        if($scope)abort_unless(in_array($scope,$scopes,true),403,'This page is outside your staff permissions.');
        if($scope==='Staff')abort_unless(Access::owner($request->user()),403,'Only an owner can manage staff permissions.');
        $columns=['id','username','status','blurb','pfp','created','lastlogin','Dius','slots'];
        if($section==='account')$columns=[...$columns,'email','verified','diubanned'];
        if($section==='avatar')$columns[]='avatar';
        $subject=DB::connection('finobe')->table('users')->select($columns)->where('id',$id)->first();
        abort_unless($subject,404,'User not found.');
        if(!in_array('Economy',$scopes,true))unset($subject->Dius);
        $sections=array_filter(self::USER_SECTIONS,fn($item)=>!$item[1] || (in_array($item[1],$scopes,true) && ($item[1]!=='Staff'||Access::owner($request->user()))));
        $actions=array_values(array_filter(Catalog::all(),fn($a)=>$a['target']==='user' && $a['group']===$scope && !Catalog::readOnly($a)));
        if($section==='notes')$actions=array_values(array_filter($actions,fn($a)=>$a['kind']==='note'));
        if($section==='moderation')$actions=array_values(array_filter($actions,fn($a)=>$a['kind']!=='note'));
        return ['actor'=>$request->user(),'subject'=>$subject,'scopes'=>$scopes,'section'=>$section,'sectionTitle'=>$title,'sections'=>$sections,'actions'=>$actions,'navbarHtml'=>$this->navbar($request)];
    }
    public function manageUser(Request $request,string $id,string $section='overview') {
        $data=$this->userPageData($request,$id,$section);$db=DB::connection('finobe');$subject=$data['subject'];
        $request->validate(['page'=>'nullable|integer|min:1|max:10000','bans_page'=>'nullable|integer|min:1|max:10000','warnings_page'=>'nullable|integer|min:1|max:10000']);
        $ban=$this->activeBans($db->table('bans')->where('username',$subject->username))->orderByDesc('id')->first(['id','reason','moderator','expire','perm','date']);
        $warning=$db->table('warning')->where('username',$subject->username)->where('reactivated',Workbench::flag('warning','reactivated',false))->where('undone',Workbench::flag('warning','undone',false))->orderByDesc('id')->first(['id','reason','moderator','date']);
        $data['activeWarning']=in_array('Moderation',$data['scopes'],true)?$warning:null;
        $data['accountState']=$ban?(in_array($ban->perm,[true,1,'1','y'],true)?'Permanently suspended':'Temporarily banned'):($warning?'Warning awaiting acknowledgement':'Active');
        $data['activeBan']=in_array('Moderation',$data['scopes'],true)?$ban:null;
        $data['restrictions']=in_array('Access restrictions',$data['scopes'],true)?$db->table('admin_restrictions')->where('user_id',$id)->where('blocked',true)->pluck('feature')->all():[];
        if($section==='moderation'){
            $data['bans']=$db->table('bans')->where('username',$subject->username)->orderByDesc('id')->paginate(20,Workbench::COLUMNS['bans'],'bans_page')->withQueryString();
            $data['warnings']=$db->table('warning')->where('username',$subject->username)->orderByDesc('id')->paginate(20,Workbench::COLUMNS['warning'],'warnings_page')->withQueryString();
        }
        if($section==='notes')$data['records']=$db->table('admin_notes')->leftJoin('users','users.id','=','admin_notes.actor_id')->where('admin_notes.user_id',$id)->orderByDesc('admin_notes.id')->paginate(25,['admin_notes.id','admin_notes.body','admin_notes.actor_id','admin_notes.created_at','users.username as moderator'])->withQueryString();
        if($section==='economy')$data['records']=$db->table('purchases')->where('username',$subject->username)->orderByDesc('id')->paginate(25,Workbench::COLUMNS['purchases'])->withQueryString();
        if($section==='changes')$data['records']=$db->table('admin_audit')->where('target_type','user')->where('target_id',(string)$id)->orderByDesc('id')->paginate(25,['id','actor_id','actor_name','label','reason','created_at'])->withQueryString();
        if($section==='permissions')$data['permissions']=Access::scopes(\App\Models\User::find($id));
        return view('admin.user', $data);
    }
    public function userAction(Request $request,string $id,Workbench $workbench) {
        $request->validate(['section'=>'required|string|max:30','action'=>'required|string|max:160']);
        $data=$this->userPageData($request,$id,$request->input('section'));
        $action=collect($data['actions'])->firstWhere('id',$request->input('action'));
        abort_unless($action,403,'This action is not available on this user page.');

        $input=array_merge($request->all(),['target'=>(string)$data['subject']->id]);
        $result=$workbench->run($action,$input,$request->user());
        return redirect('/admin/users/'.$id.'/'. $request->input('section'))->with('admin_success',$result['message']);
    }
    public function acknowledge(Request $request) {
        abort_unless($request->user(),403);
        $request->validate(['acknowledged'=>'required|accepted']);
        $db=DB::connection('finobe');
        $db->table('warning')->where('username',$request->user()->username)
            ->update(['reactivated'=>Workbench::flag('warning','reactivated',true)]);
        return redirect('/');
    }
}
