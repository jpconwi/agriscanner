<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Advisory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdvisoryController extends Controller
{
    public function index(Request $request)
    {
        $q = Advisory::published()->latest('published_at');
        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $op = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $q->where(fn ($qq) => $qq->where('title', $op, $like)->orWhere('summary', $op, $like)->orWhere('category', $op, $like));
        }

        return view('advisories.index', ['advisories' => $q->paginate(12)->withQueryString(), 'term' => $term]);
    }

    public function show(int $id)
    {
        return view('advisories.show', ['advisory' => Advisory::published()->findOrFail($id)]);
    }
}
