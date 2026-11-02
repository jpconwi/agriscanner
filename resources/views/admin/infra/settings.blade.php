<form method="POST" action="{{ route('admin.settings.update') }}" class="card max-w-xl space-y-4 p-6">
    @csrf @method('PUT')
    <x-field name="default_quota_mb" label="Default quota per new account (MB)" for="s-quota" hint="Per-user quotas can be set on the Users page. These limits are separate from Cloudflare R2 and Neon limits.">
        <input id="s-quota" type="number" min="1" step="1" name="default_quota_mb" value="{{ old('default_quota_mb', (int) round($settings['default_quota_bytes'] / 1048576)) }}" required class="input">
    </x-field>
    <div class="grid gap-4 sm:grid-cols-3">
        <x-field name="warn_percent" label="Quota warning %" for="s-warn"><input id="s-warn" type="number" min="1" max="100" name="warn_percent" value="{{ old('warn_percent', $settings['warn_percent']) }}" required class="input"></x-field>
        <x-field name="critical_percent" label="Quota critical %" for="s-crit"><input id="s-crit" type="number" min="1" max="100" name="critical_percent" value="{{ old('critical_percent', $settings['critical_percent']) }}" required class="input"></x-field>
        <x-field name="db_warn_percent" label="Database warning %" for="s-db"><input id="s-db" type="number" min="1" max="100" name="db_warn_percent" value="{{ old('db_warn_percent', $settings['db_warn_percent']) }}" required class="input"></x-field>
    </div>
    <button class="btn btn-primary">Save settings</button>
</form>
