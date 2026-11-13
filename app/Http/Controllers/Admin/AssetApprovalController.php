<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Access;
use App\Admin\Catalog;
use App\Admin\Workbench;
use App\Http\Controllers\Controller;
use App\View\PageData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AssetApprovalController extends Controller
{
    private function authorizeReview(Request $request, string $decision = 'approve'): void
    {
        Access::authorize($request->user(), Catalog::find('catalog-' . $decision . '-asset'));
    }

    private function pageData(Request $request): array
    {
        return [
            'actor' => $request->user(),
            'navbarHtml' => app(PageData::class)->adminNavbar(),
        ];
    }

    private function query()
    {
        return DB::connection('finobe')->table('assets')
            ->leftJoin('users', 'users.id', '=', 'assets.author')
            ->select('assets.*', 'users.username as uploader');
    }

    private function prepare(object $asset): object
    {
        $additional = is_string($asset->additional ?? null)
            ? json_decode($asset->additional, true)
            : ($asset->additional ?? []);
        $additional = is_array($additional) ? $additional : [];
        $media = isset($additional['media']) && is_array($additional['media']) ? $additional['media'] : [];

        $thumbnail = $media['thumbnail'] ?? null;
        if (!is_string($thumbnail) || !preg_match('~^(https?://|/(?!/))~i', $thumbnail)) {
            $thumbnail = null;
        }

        $filename = (string) ($asset->file ?? '');
        $validFilename = $filename !== '' && basename($filename) === $filename;
        $pendingFile = $validFilename ? '/dynamic/reviewing/' . rawurlencode($filename) : null;
        $asset->preview_image = $thumbnail;
        if (!$asset->preview_image && $pendingFile && preg_match('/\.(?:png|jpe?g|gif|webp)$/i', $filename)) {
            $asset->preview_image = $pendingFile;
        }
        $asset->preview_audio = (int) $asset->asset_type === 3 ? $pendingFile : null;
        $asset->texture_id = isset($media['textureAssetId']) && ctype_digit((string) $media['textureAssetId'])
            ? (string) $media['textureAssetId']
            : null;
        $asset->type_name = match ((int) $asset->asset_type) {
            2 => 'T-Shirt', 3 => 'Audio', 8 => 'Hat', 9 => 'Place', 10 => 'Model',
            11 => 'Shirt', 12 => 'Pants', 18 => 'Face', default => 'Asset',
        };
        $asset->uploader = $asset->uploader ?: 'User #' . $asset->author;

        return $asset;
    }

    public function index(Request $request)
    {
        $this->authorizeReview($request);
        $request->validate(['page' => 'nullable|integer|min:1|max:10000']);
        $assets = $this->query()
            ->where('assets.visibility', 'r')
            ->where('assets.asset_type', '!=', 1)
            ->orderByDesc('assets.id')
            ->paginate(24)
            ->withQueryString();
        $assets->getCollection()->transform(fn ($asset) => $this->prepare($asset));

        return view('admin.assets', $this->pageData($request) + ['assets' => $assets]);
    }

    public function show(Request $request, string $id)
    {
        $this->authorizeReview($request);
        abort_unless(ctype_digit($id), 404);
        $asset = $this->query()->where('assets.id', $id)->first();
        abort_unless($asset, 404, 'Asset not found.');

        return view('admin.asset', $this->pageData($request) + ['asset' => $this->prepare($asset)]);
    }

    public function decide(Request $request, string $id, string $decision, Workbench $workbench)
    {
        abort_unless(ctype_digit($id) && in_array($decision, ['approve', 'deny'], true), 404);
        $this->authorizeReview($request, $decision);
        $input = $request->validate([
            'request_id' => 'required|uuid',
            'reason' => 'nullable|string|min:3|max:500',
        ]);
        $asset = DB::connection('finobe')->table('assets')->where('id', $id)->first();
        abort_unless($asset, 404, 'Asset not found.');
        if ($asset->visibility !== 'r') {
            return redirect('/admin/assets')->with('admin_error', 'This asset has already been reviewed.');
        }

        try {
            $workbench->run(Catalog::find('catalog-' . $decision . '-asset'), [
                'target' => $id,
                'reason' => trim((string) ($input['reason'] ?? ''))
                    ?: ($decision === 'approve' ? 'Approved from the asset queue' : 'Denied from the asset queue'),
                'request_id' => $input['request_id'],
                'confirm' => $decision === 'deny' ? '1' : null,
            ], $request->user());
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 422) {
                throw $error;
            }
            return redirect('/admin/assets/' . $id)->with('admin_error', $error->getMessage());
        }

        return redirect('/admin/assets')->with('admin_success',
            'Asset #' . $id . ($decision === 'approve' ? ' approved.' : ' denied.'));
    }
}
