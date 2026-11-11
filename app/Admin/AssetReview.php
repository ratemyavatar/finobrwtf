<?php
namespace App\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class AssetReview
{
    public function apply(object $asset,bool $approve): array
    {
        abort_unless(($asset->visibility ?? null) === 'r', 409, 'Asset has already been reviewed.');
        $db=DB::connection('finobe');
        $additional=json_decode($asset->additional??'{}',true)?:[];
        $ids=array_filter([$asset->id,$additional['media']['textureAssetId']??null]);
        $records=$db->table('assets')->whereIn('id',$ids)->lockForUpdate()->get();
        $moves=[];
        foreach($records as $record) {
            abort_unless($record->file && basename($record->file)===$record->file,422,'Invalid asset filename.');
            $folder=(int)$record->asset_type===3?'audios':'assets';
            $live=rtrim(config('admin.cdn_path'),'/').'/'.$folder.'/'.$record->file;
            $denied=public_path('dynamic/denied/'.$record->file);
            $review=public_path('dynamic/reviewing/'.$record->file);
            $source=collect([$live,$denied,$review])->first(fn($path)=>is_file($path));
            abort_unless($source,422,'Asset file is missing. No review changes were made.');
            $destination=$approve?$live:$denied;
            if($source!==$destination)$moves[]=[$source,$destination];
        }
        $done=[];
        try {
            foreach($moves as [$from,$to]) {
                File::ensureDirectoryExists(dirname($to));
                abort_if(is_file($to),422,'Destination file already exists.');
                abort_unless(File::move($from,$to),422,'Unable to move the asset file.');
                $done[]=[$from,$to];
            }
            $db->table('assets')->whereIn('id',$ids)->update(['visibility'=>$approve?'n':'d']);
        } catch(\Throwable $e) {
            foreach(array_reverse($done) as [$from,$to])File::move($to,$from);
            throw $e;
        }
        return ['asset_ids'=>array_values($ids),'visibility'=>$approve?'n':'d'];
    }
}
