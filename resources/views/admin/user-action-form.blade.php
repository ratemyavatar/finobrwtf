@if(count($actions))
@php($selectedAction=collect($actions)->firstWhere('id',old('action')) ?? $actions[0])
<form method="post" action="/admin/users/{{ $subject->id }}/actions" id="user-action-form" class="card card-body mb-4">
@csrf
<input type="hidden" name="section" value="{{ $section }}">
<input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::uuid()) }}">
@if(count($actions)>1)
<label for="user-action">Action</label><select id="user-action" name="action" class="form-control mb-3">@foreach($actions as $action)<option value="{{ $action['id'] }}" @selected($selectedAction['id']===$action['id'])>{{ $action['label'] }}</option>@endforeach</select>
@else<input type="hidden" name="action" value="{{ $selectedAction['id'] }}"><h5>{{ $selectedAction['label'] }}</h5>@endif
<div id="user-action-value" @if(!isset($selectedAction['input'])) hidden @endif>
@if(isset($selectedAction['input']))
<label for="action-value">{{ in_array($section,['economy','permissions']) ? ($section==='economy' ? (str_contains($selectedAction['kind'],'inventory') ? 'Asset ID' : 'Dius') : 'Permission') : 'New value' }}</label>
@if(str_starts_with($selectedAction['input'],'in:'))<select id="action-value" name="value" class="form-control mb-3" required>@foreach(explode(',',substr($selectedAction['input'],3)) as $value)<option value="{{ $value }}" @selected(old('value')===$value)>{{ $value }}</option>@endforeach</select>
@else<input id="action-value" name="value" class="form-control mb-3" value="{{ old('value') }}" type="{{ str_starts_with($selectedAction['input'],'email') ? 'email' : (str_contains($selectedAction['input'],'integer') ? 'number' : 'text') }}" required>@endif
@endif
</div>
<label for="user-action-reason">{{ $section==='notes' ? 'Private note' : 'Reason' }}</label>
<textarea id="user-action-reason" name="reason" class="form-control mb-3" rows="3" minlength="3" maxlength="2000" required>{{ old('reason') }}</textarea>
<div id="user-action-confirm" @if(empty($selectedAction['danger'])) hidden @endif><label><input type="checkbox" name="confirm" value="1" @if(!empty($selectedAction['danger'])) required @else disabled @endif> I checked the account and understand this action.</label></div>
<button type="submit" class="btn btn-primary align-self-start">{{ $section==='notes' ? 'Add note' : 'Apply action' }}</button>
</form>
@endif
