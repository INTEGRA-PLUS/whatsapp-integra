<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;

class LogsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeMaster();

        $logsPath = storage_path('logs');
        $files = [];

        if (File::isDirectory($logsPath)) {
            foreach (File::files($logsPath) as $file) {
                if (strtolower($file->getExtension()) !== 'log') {
                    continue;
                }

                $files[] = [
                    'name' => $file->getFilename(),
                    'size' => $file->getSize(),
                    'size_human' => $this->formatBytes($file->getSize()),
                    'modified_at' => date('Y-m-d H:i:s', $file->getMTime()),
                ];
            }
        }

        usort($files, fn($a, $b) => strcmp($b['modified_at'], $a['modified_at']));

        return Inertia::render('Master/Logs/Index', [
            'logs' => $files,
        ]);
    }

    public function show(string $file)
    {
        $this->authorizeMaster();

        $path = $this->resolveLogPath($file);

        $content = File::exists($path) ? File::get($path) : '';
        $size = File::exists($path) ? File::size($path) : 0;

        return Inertia::render('Master/Logs/Show', [
            'log' => [
                'name' => basename($path),
                'size' => $size,
                'size_human' => $this->formatBytes($size),
                'modified_at' => File::exists($path) ? date('Y-m-d H:i:s', File::lastModified($path)) : null,
                'content' => $content,
            ],
        ]);
    }

    public function destroy(string $file)
    {
        $this->authorizeMaster();

        $path = $this->resolveLogPath($file);

        if (File::exists($path)) {
            File::delete($path);
        }

        return redirect()->route('master.logs.index')->with('success', 'Log eliminado correctamente.');
    }

    public function clear(string $file)
    {
        $this->authorizeMaster();

        $path = $this->resolveLogPath($file);

        if (File::exists($path)) {
            File::put($path, '');
        }

        return redirect()->back()->with('success', 'Log vaciado correctamente.');
    }

    private function resolveLogPath(string $file): string
    {
        $name = basename($file);

        if ($name === '' || $name[0] === '.' || !preg_match('/\.log$/i', $name)) {
            abort(404);
        }

        $path = storage_path('logs/' . $name);
        $realLogsDir = realpath(storage_path('logs'));
        $realPath = realpath($path);

        if ($realPath === false || $realLogsDir === false || strpos($realPath, $realLogsDir) !== 0) {
            abort(404);
        }

        return $realPath;
    }

    private function authorizeMaster(): void
    {
        if (!Auth::user() || !Auth::user()->isMaster()) {
            abort(403, 'Acceso denegado. Solo usuarios Master.');
        }
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }
}
