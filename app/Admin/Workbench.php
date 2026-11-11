<?php
namespace App\Admin;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class Workbench
{
    public const TABLES = ['user'=>'users','assets'=>'assets','bans'=>'bans','warning'=>'warning','forum_threads'=>'forum_threads','forum_replies'=>'forum_replies','messages'=>'messages','pms'=>'pms','purchases'=>'purchases','invitekeys'=>'invitekeys','videos'=>'videos','announcements'=>'announcements'];
    public const COLUMNS = [
        'users'=>['id','username','email','status','Dius','verified','diubanned','slots','blurb','pfp','version','branding','theme','lastlogin','created'],
        'assets'=>['id','asset_type','title','author','description','visibility','additional','created'],
        'bans'=>['id','username','reason','expire','moderator','perm','reactivated','date','undone'],
        'warning'=>['id','username','reason','moderator','reactivated','date','undone'],
        'forum_threads'=>['id','author','category','title','comment','date','pinned','locked','edited'],
        'forum_replies'=>['id','author','toid','comment','date','sticked','edited'],
        'messages'=>['id','author','touser','subject','message','date','readed','archived'],
        'pms'=>['id','touser','owner','message','date','readed'],
        'purchases'=>['id','username','assetid','serial','date','author','amount','type'],
        'invitekeys'=>['id','author','IID','creation','dateUsed','used','usedBy'],
        'videos'=>['id','title','author','thumbnail','description','date'],
        'announcements'=>['id','author','message','expire','color'],
    ];

    private function db() { return DB::connection('finobe'); }
    private function query(array $a) { return $this->db()->table(self::TABLES[$a['target']]); }
    private function publicRow($row): array {
        return Arr::except((array)$row,['password','token','remember_token','ip','payload','exception','email_verified_at']);
    }
    public static function flag(string $table,string $column,bool $value) {
        $type=Schema::connection('finobe')->getColumnType($table,$column);
        return in_array($type,['string','varchar','char','text']) ? ($value?'y':'n') : $value;
    }
    private function rows($q,string $table,int $page=1): array {
        $columns=array_values(array_intersect(self::COLUMNS[$table]??[],Schema::connection('finobe')->getColumnListing($table)));
        return $q->select($columns)->orderByDesc('id')->offset(($page-1)*50)->limit(50)->get()->map(fn($r)=>$this->publicRow($r))->all();
    }
    public function target(array $a,string $target): ?object {
        if (($a['target']??'none')==='none') return null;
        $q=$this->query($a);
        $a['target']==='user' && !ctype_digit($target) ? $q->where('username',$target) : $q->where('id',$target);
        if(isset($a['asset_type'])) $q->where('asset_type',(string)$a['asset_type']);
        $row=$q->first();
        abort_unless($row,422,'Target not found, or the asset has a different type.');
        return $row;
    }
    public function run(array $a,array $input,$actor): array {
        Access::authorize($actor,$a);
        $read=Catalog::readOnly($a);
        $rules=['target'=>'nullable|string|max:80','reason'=>($read?'nullable':'required').'|string|min:3|max:2000','page'=>'nullable|integer|min:1|max:10000','search'=>'nullable|string|max:120'];
        if(isset($a['input'])) $rules['value']='required|'.$a['input'];
        if(!$read) $rules['request_id']='required|uuid';
        if(!empty($a['danger'])) $rules['confirm']='required|accepted';
        Validator::make($input,$rules)->validate();
        if($read) return $this->read($a,$input,$actor);
        return $this->db()->transaction(function()use($a,$input,$actor){
            $fresh=\App\Models\User::where('id',$actor->id)->lockForUpdate()->first();
            Access::authorize($fresh,$a);
            $prior=$this->db()->table('admin_audit')->where('request_id',$input['request_id'])->first();
            if($prior) {
                abort_unless((int)$prior->actor_id===(int)$actor->id && $prior->action===$a['id'],409,'This request ID has already been used.');
                return ['message'=>'Already applied; no duplicate change was made.','audit_id'=>$prior->id];
            }
            $target=$this->target($a,(string)($input['target']??''));
            if($target) {
                $this->query($a)->where('id',$target->id)->lockForUpdate()->first();
                $target=$this->target($a,(string)$target->id);
            }
            if($a['target']==='user' && $target && $target->status==='admin' && in_array($a['group'],['Moderation','Access restrictions','Staff','Economy','Accounts'])) {
                abort_unless(Access::owner($fresh),403,'Only an owner can change an administrator.');
                abort_if(in_array($a['kind'],['ban','restriction','staff_revoke']) && (int)$target->id===(int)$actor->id,422,'You cannot suspend or remove your own staff access.');
            }
            $before=$target?$this->publicRow($target):null;
            $result=$this->mutate($a,$input,$actor,$target);
            $after=$target?$this->publicRow($this->target($a,(string)$target->id)):null;
            $audit=$this->db()->table('admin_audit')->insertGetId([
                'actor_id'=>$actor->id,'actor_name'=>$actor->username,'action'=>$a['id'],'label'=>$a['label'],
                'target_type'=>$a['target'],'target_id'=>$target?->id,'reason'=>$input['reason'],
                'before'=>json_encode($before,JSON_THROW_ON_ERROR),'after'=>json_encode(['record'=>$after,'result'=>$result],JSON_THROW_ON_ERROR),
                'request_id'=>$input['request_id'],'created_at'=>now(),
            ]);
            return ['message'=>$a['label'].' completed.','audit_id'=>$audit,'result'=>$result];
        },3);
    }
    private function read(array $a,array $in,$actor): array {
        $kind=$a['kind'];$db=$this->db();$page=(int)($in['page']??1);
        if($kind==='report') {
            $q=$this->query($a);$table=self::TABLES[$a['target']];
            if(isset($a['asset_type']))$q->where('asset_type',(string)$a['asset_type']);
            if($a['id']==='moderation-active-bans')$q->where(fn($q)=>$q->where('perm',self::flag('bans','perm',true))->orWhere('expire','>',now()));
            foreach($a['filters']??[] as [$field,$op,$val]) {
                if(is_bool($val) && !str_contains($field,'->'))$val=self::flag($table,$field,$val);
                if($op==='recent')$q->where($field,'>=',now()->subDays($val));
                elseif($op==='older')$q->where($field,'<',now()->subDays($val));
                else $q->where($field,$op,$val);
            }
            if(!empty($in['search'])) {
                $column=['users'=>'username','assets'=>'title','videos'=>'title','forum_threads'=>'title','messages'=>'subject','bans'=>'username','warning'=>'username','purchases'=>'username','invitekeys'=>'IID','announcements'=>'message','forum_replies'=>'comment','pms'=>'message'][$table];
                $q->where($column,'like','%'.$in['search'].'%');
            }
            $count=(clone $q)->count();
            if(isset($a['sort']))$q->orderByDesc($a['sort']);
            return ['rows'=>$this->rows($q,$table,$page),'total'=>$count,'page'=>$page,'per_page'=>50];
        }
        if(in_array($kind,['audit','audit_mine','audit_export','target_audit'])) {
            $q=$db->table('admin_audit');
            if($kind==='audit_mine')$q->where('actor_id',$actor->id);
            if($kind==='target_audit'){$u=$this->target($a,(string)($in['target']??''));$q->where('target_type','user')->where('target_id',(string)$u->id);}
            return ['rows'=>$q->select('id','actor_name','label','target_type','target_id','reason','created_at')->orderByDesc('id')->offset(($page-1)*50)->limit(50)->get()->toArray(),'page'=>$page];
        }
        if($kind==='counts')return ['rows'=>collect(self::TABLES)->unique()->map(fn($t)=>['table'=>$t,'records'=>$db->table($t)->count()])->values()->all()];
        if($kind==='health')return ['database'=>(int)$db->select('SELECT 1 AS healthy')[0]->healthy===1?'Connected':'Unknown','connection'=>'finobe'];
        if(in_array($kind,['queue_pending','queue_failed'])) {
            $table=$kind==='queue_pending'?'jobs':'failed_jobs';
            return ['rows'=>$db->table($table)->select($table==='jobs'?['id','queue','attempts','available_at']:['id','uuid','queue','failed_at'])->orderByDesc('id')->limit(50)->get()->toArray()];
        }
        if($kind==='staff_list')return ['rows'=>$db->table('admin_staff')->join('users','users.id','=','admin_staff.user_id')->select('users.id','users.username','admin_staff.scopes')->get()->toArray()];
        $row=$this->target($a,(string)($in['target']??''));
        if(in_array($kind,['inspect','export']))return ['rows'=>[$this->publicRow($row)]];
        if($kind==='history')return ['bans'=>$this->rows($db->table('bans')->where('username',$row->username),'bans'),'warnings'=>$this->rows($db->table('warning')->where('username',$row->username),'warning')];
        if($kind==='notes')return ['rows'=>$db->table('admin_notes')->where('user_id',$row->id)->orderByDesc('id')->limit(50)->get()->toArray()];
        if(in_array($kind,['avatar_inspect','avatar_export']))return ['avatar'=>json_decode($row->avatar??'[]',true)];
        if($kind==='staff_inspect')return ['scopes'=>Access::scopes(\App\Models\User::find($row->id))];
        if($kind==='flair_inspect')return ['flair'=>$db->table('admin_flair')->where('user_id',$row->id)->first()];
        abort(422,'Unsupported read operation.');
    }
    private function mutate(array $a,array $in,$actor,?object $row) {
        $db=$this->db();$kind=$a['kind'];$value=$a['value']??$in['value']??null;
        $table=self::TABLES[$a['target']]??null;
        if(isset($a['input']) && str_contains($a['input'],'integer'))$value=(int)$value;
        $save=function(array $changes)use($table,$row,$db){$db->table($table)->where('id',$row->id)->update($changes);return $changes;};
        if($kind==='field') {
            $field=$a['field'];
            if($field==='username') {
                abort_if($row->username!==$value && $db->table('users')->where('username',$value)->exists(),422,'That username is taken.');

                foreach(['bans','warning','purchases','verify_email','reset_password'] as $related) $db->table($related)->where('username',$row->username)->update(['username'=>$value]);
            }
            if($field==='email') {
                abort_if($db->table('users')->where('email',$value)->where('id','!=',$row->id)->exists(),422,'That email is already used.');
                $save(['verified'=>'n','email_verified_at'=>null]);
            }
            if(is_bool($value))$value=self::flag($table,$field,$value);
            if($field==='pfp')abort_unless(preg_match('~^avatar/[A-Za-z0-9_-]+\.(png|jpg|jpeg|gif|webp)$~D',$value),422,'Use a CDN-relative avatar image path, such as avatar/example.png.');
            $changes=[$field=>$value];
            if(in_array($table,['forum_threads','forum_replies']) && in_array($field,['comment','title']))$changes+=['edited'=>self::flag($table,'edited',true),'edited_date'=>now()];
            return $save($changes);
        }
        if($kind==='json') {
            $data=json_decode($row->additional??'{}',true)?:[];
            Arr::set($data,substr($a['field'],11),$value);
            return $save(['additional'=>json_encode($data,JSON_THROW_ON_ERROR)]);
        }
        if(in_array($kind,['ban','warn','remind'])) {
            abort_if((int)$row->id===(int)$actor->id,422,'You cannot moderate yourself.');
            $t=$kind==='ban'?'bans':'warning';
            $reason=($a['preset']??'').($a['preset']??false?': ':'').$in['reason'];
            $data=['username'=>$row->username,'moderator'=>$actor->username,'reason'=>$reason,'date'=>now(),'undone'=>self::flag($t,'undone',false),'reactivated'=>self::flag($t,'reactivated',false)];
            if($kind==='ban')$data+=['perm'=>self::flag($t,'perm',!isset($a['days'])),'expire'=>isset($a['days'])?now()->addDays($a['days']):null];
            return ['record_id'=>$db->table($t)->insertGetId($data),'type'=>$t];
        }
        if(in_array($kind,['unban','unwarn'])) {
            $t=$kind==='unban'?'bans':'warning';
            return ['updated'=>$db->table($t)->where('username',$row->username)->update(['reactivated'=>self::flag($t,'reactivated',true),'undone'=>self::flag($t,'undone',true)])];
        }
        if($kind==='note')return ['note_id'=>$db->table('admin_notes')->insertGetId(['user_id'=>$row->id,'actor_id'=>$actor->id,'body'=>$in['reason'],'created_at'=>now()])];
        if($kind==='sessions') {
            $save(['remember_token'=>null,'token'=>Str::random(60)]);
            return ['sessions_removed'=>$db->table('sessions')->where('user_id',$row->id)->delete()];
        }
        if($kind==='token'){$save(['token'=>Str::random(60)]);return ['rotated'=>true];}
        if($kind==='restriction') {
            $before=$db->table('admin_restrictions')->where('user_id',$row->id)->where('feature',$a['feature'])->value('blocked');
            $db->table('admin_restrictions')->updateOrInsert(['user_id'=>$row->id,'feature'=>$a['feature']],['blocked'=>$value]);
            return ['feature'=>$a['feature'],'before'=>$before,'blocked'=>$value];
        }
        if(in_array($kind,['site_switch','cooldown'])) {
            $key=($kind==='site_switch'?'pause.':'cooldown.').$a['feature'];$old=$db->table('admin_settings')->where('key',$key)->value('value');
            $db->table('admin_settings')->updateOrInsert(['key'=>$key],['value'=>(string)(is_bool($value)?(int)$value:$value)]);
            return ['key'=>$key,'before'=>$old,'value'=>$value];
        }
        if(in_array($kind,['balance','credit','debit'])) {
            $balance=(int)$row->Dius; $amount=(int)$value;
            $next=match($kind){'balance'=>$amount,'credit'=>$balance+$amount,'debit'=>$balance-$amount};
            abort_if($next<0||$next>100000000,422,'The resulting balance must be between 0 and 100,000,000.');
            $save(['Dius'=>$next]);
            $db->table('purchases')->insert(['username'=>$row->username,'assetid'=>0,'serial'=>0,'author'=>$actor->id,'amount'=>$next-$balance,'type'=>'admin','date'=>now(),'lastchanged'=>now()]);
            return ['before'=>$balance,'after'=>$next,'delta'=>$next-$balance];
        }
        if(in_array($kind,['inventory_grant','inventory_revoke'])) {
            abort_unless($db->table('assets')->where('id',$value)->exists(),422,'Asset does not exist.');
            $q=$db->table('purchases')->where('username',$row->username)->where('assetid',$value)->where('type','1');
            if($kind==='inventory_revoke') {
                $avatar=json_decode($row->avatar??'[]',true)?:[[]];
                $avatar[0]['equippedGearVersionIds']=array_values(array_diff($avatar[0]['equippedGearVersionIds']??[],[(int)$value]));
                $avatar[0]['backpackGearVersionIds']=array_values(array_diff($avatar[0]['backpackGearVersionIds']??[],[(int)$value]));
                $save(['avatar'=>json_encode($avatar)]);
                return ['revoked'=>$q->delete()];
            }
            abort_if($q->exists(),422,'This account already owns the item.');
            return ['purchase_id'=>$db->table('purchases')->insertGetId(['username'=>$row->username,'assetid'=>$value,'serial'=>0,'author'=>$actor->id,'amount'=>0,'type'=>'1','date'=>now(),'lastchanged'=>now()])];
        }
        if(str_starts_with($kind,'avatar_')) {
            $avatar=json_decode($row->avatar??'[]',true)?:[[]];
            if($kind==='avatar_color')$avatar[0]['bodyColors'][$a['field']]=$value;
            elseif($kind==='avatar_noob')$avatar=[['resolvedAvatarType'=>'R6','equippedGearVersionIds'=>[],'backpackGearVersionIds'=>[],'assetAndAssetTypeIds'=>[],'bodyColors'=>['headColorId'=>24,'torsoColorId'=>23,'leftArmColorId'=>24,'rightArmColorId'=>24,'leftLegColorId'=>119,'rightLegColorId'=>119]]];
            else {
                $types=['avatar_clear_hats'=>8,'avatar_clear_gear'=>19,'avatar_clear_shirt'=>11,'avatar_clear_pants'=>12,'avatar_clear_tshirt'=>2,'avatar_clear_face'=>18];
                $type=$types[$kind];
                $ids=$db->table('assets')->where('asset_type',(string)$type)->pluck('id')->all();
                $avatar[0]['equippedGearVersionIds']=array_values(array_diff($avatar[0]['equippedGearVersionIds']??[],$ids));
                $avatar[0]['assetAndAssetTypeIds']=array_values(array_filter($avatar[0]['assetAndAssetTypeIds']??[],fn($x)=>!is_array($x)||($x['assetTypeId']??null)!=$type));
            }
            return $save(['avatar'=>json_encode($avatar,JSON_THROW_ON_ERROR)]);
        }
        if($kind==='message')return ['message_id'=>$db->table('messages')->insertGetId(['author'=>$actor->id,'touser'=>$row->id,'subject'=>$a['label'],'message'=>$value,'date'=>now(),'readed'=>self::flag('messages','readed',false),'archived'=>self::flag('messages','archived',false)])];
        if($kind==='invite') {
            $keys=[];
            for($i=0;$i<$a['quantity'];$i++) {
                $key=Str::upper(Str::random(24));$db->table('invitekeys')->insert(['author'=>$row->id,'IID'=>$key,'creation'=>now(),'used'=>self::flag('invitekeys','used',false)]);$keys[]=$key;
            }
            return ['invite_keys'=>$keys];
        }
        if($kind==='invite_revoke') {
            abort_if(in_array($row->used,[true,1,'1','y'],true),422,'Used invites cannot be revoked.');

            return $save(['used'=>self::flag('invitekeys','used',true),'dateUsed'=>now()]);
        }
        if($kind==='announcement')return ['announcement_id'=>$db->table('announcements')->insertGetId(['author'=>$actor->id,'message'=>$in['reason'],'date'=>now(),'expire'=>now()->addDay(),'color'=>'info'])];
        if($kind==='expire')return $save([$a['field']=>now()]);
        if($kind==='extend')return $save([$a['field']=>\Carbon\Carbon::parse($row->{$a['field']})->max(now())->addDays($a['days'])]);
        if($kind==='staff_scope'||$kind==='staff_revoke') {
            abort_if($row->status==='admin',422,'Full administrators are managed outside delegated staff permissions.');
            $old=json_decode($db->table('admin_staff')->where('user_id',$row->id)->value('scopes')??'[]',true);
            $next=$old;
            if($kind==='staff_revoke')$next=[];
            elseif($a['grant'])$next=array_values(array_unique([...$old,$in['value']]));
            else $next=array_values(array_diff($old,[$in['value']]));
            $db->table('admin_staff')->updateOrInsert(['user_id'=>$row->id],['scopes'=>json_encode($next)]);
            return ['before'=>$old,'scopes'=>$next];
        }
        if($kind==='flair'||str_starts_with($kind,'flair_clear')||$kind==='flair_reset') {
            $old=(array)$db->table('admin_flair')->where('user_id',$row->id)->first();
            $changes=$kind==='flair'?[$a['field']=>$value]:($kind==='flair_reset'?['title'=>null,'effect'=>null,'accent'=>null]:[substr($kind,12)=>null]);
            $db->table('admin_flair')->updateOrInsert(['user_id'=>$row->id],$changes);
            return ['before'=>$old,'changes'=>$changes];
        }
        if($kind==='asset_approve'||$kind==='asset_deny')return app(AssetReview::class)->apply($row,$kind==='asset_approve');
        if($kind==='queue_forget') {
            Validator::make($in,['value'=>'required|integer|min:1'])->validate();
            return ['forgotten'=>$db->table('failed_jobs')->where('id',$in['value'])->delete()];
        }
        if($kind==='cache_users'){Cache::forget('user_count');return ['cleared'=>'user_count'];}
        abort(422,'Unsupported mutation.');
    }
}
