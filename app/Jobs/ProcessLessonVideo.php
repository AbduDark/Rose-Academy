<?php

namespace App\Jobs;

use App\Models\Lesson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ProcessLessonVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $lesson;
    public $timeout = 3600; // ساعة واحدة للمعالجة
    public $tries = 3;

    public function __construct(Lesson $lesson)
    {
        $this->lesson = $lesson;
    }

    public function handle()
    {
        try {
            Log::info("بدء معالجة فيديو الدرس: {$this->lesson->id}");

            // التحقق من وجود الفيديو
            $videoPath = storage_path("app/{$this->lesson->video_path}");
            if (!file_exists($videoPath)) {
                throw new \Exception("ملف الفيديو غير موجود: {$videoPath}");
            }

            $videoSize = filesize($videoPath);
            Log::info("حجم الفيديو: " . number_format($videoSize / 1024 / 1024, 2) . " MB");

            // التحقق من توفر FFmpeg
            $this->checkFFmpegAvailability();

            // إنشاء المجلدات المطلوبة
            $outputDir = storage_path("app/private_videos/hls/lesson_{$this->lesson->id}");
            $this->createDirectories($outputDir);

            // توليد مفاتيح التشفير
            $keyData = $this->generateEncryptionKeys($outputDir);

            // معالجة الفيديو باستخدام FFmpeg
            $this->processVideoWithFFmpeg($videoPath, $outputDir, $keyData);

            // التحقق من نجاح المعالجة
            $this->verifyProcessing($outputDir);

            // تحديث قاعدة البيانات
            $this->lesson->update([
                'video_path' => "private_videos/hls/lesson_{$this->lesson->id}/index.m3u8",
                'video_status' => 'ready'
            ]);

            // حذف الفيديو المؤقت
            if (file_exists($videoPath)) {
                Storage::delete($this->lesson->video_path);
            }

            Log::info("تم الانتهاء من معالجة فيديو الدرس: {$this->lesson->id}");

        } catch (\Exception $e) {
            Log::error("خطأ في معالجة فيديو الدرس {$this->lesson->id}: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());

            $this->lesson->update(['video_status' => 'failed']);

            // تنظيف الملفات في حالة الفشل
            $this->cleanup();

            throw $e;
        }
    }

    /**
     * التحقق من توفر FFmpeg
     */
    private function checkFFmpegAvailability(): void
    {
        $process = new Process(['ffmpeg', '-version']);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception("FFmpeg غير متوفر في النظام. يرجى تثبيته أولاً.");
        }

        Log::info("FFmpeg متوفر: " . trim(explode("\n", $process->getOutput())[0]));
    }

    /**
     * إنشاء المجلدات المطلوبة
     */
    private function createDirectories(string $outputDir): void
    {
        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                throw new \Exception("فشل في إنشاء مجلد الإخراج: {$outputDir}");
            }
        }
    }

    /**
     * توليد مفاتيح التشفير AES-128
     */
    private function generateEncryptionKeys(string $outputDir): array
    {
        // توليد مفتاح عشوائي 16 بايت
        $key = random_bytes(16);
        $keyFile = "{$outputDir}/enc.key";

        if (file_put_contents($keyFile, $key) === false) {
            throw new \Exception("فشل في كتابة ملف المفتاح");
        }

        // توليد IV عشوائي
        $iv = bin2hex(random_bytes(16));

        // إنشاء ملف معلومات المفتاح
        $keyInfoFile = "{$outputDir}/enc.keyinfo";
        $keyUri = route('lesson.key', ['lesson' => $this->lesson->id]);

        $keyInfoContent = "{$keyUri}\n{$keyFile}\n{$iv}";

        if (file_put_contents($keyInfoFile, $keyInfoContent) === false) {
            throw new \Exception("فشل في كتابة ملف معلومات المفتاح");
        }

        return [
            'key_file' => $keyFile,
            'key_info_file' => $keyInfoFile,
            'iv' => $iv
        ];
    }

    /**
     * معالجة الفيديو باستخدام FFmpeg مع HLS والتشفير
     */
    private function processVideoWithFFmpeg(string $inputPath, string $outputDir, array $keyData): void
    {
        $outputFile = "{$outputDir}/index.m3u8";

        // أوامر FFmpeg محسنة للملفات الصغيرة
        $command = [
            'ffmpeg',
            '-i', $inputPath,

            // إعدادات الفيديو
            '-c:v', 'libx264',
            '-preset', 'fast', // أسرع في المعالجة
            '-crf', '28', // جودة مناسبة للملفات الصغيرة
            '-maxrate', '1M',
            '-bufsize', '2M',
            '-vf', 'scale=-2:480', // دقة أقل للملفات الصغيرة

            // إعدادات الصوت
            '-c:a', 'aac',
            '-b:a', '96k', // bitrate أقل للصوت
            '-ar', '44100',

            // إعدادات HLS
            '-f', 'hls',
            '-hls_time', '10', // مقاطع أطول لملفات أقل
            '-hls_list_size', '0',
            '-hls_segment_filename', "{$outputDir}/segment_%03d.ts",

            // إعدادات التشفير
            '-hls_key_info_file', $keyData['key_info_file'],
            '-hls_flags', 'delete_segments',

            // إعدادات إضافية للأمان
            '-hls_base_url', '',

            // ملف الإخراج
            $outputFile,

            // مخرجات مفصلة للتشخيص
            '-loglevel', 'info',
            '-y'
        ];

        $process = new Process($command);
        $process->setTimeout(1800); // 30 دقيقة للملفات الصغيرة

        Log::info("تشغيل أمر FFmpeg للدرس {$this->lesson->id}");
        Log::info("أمر FFmpeg: " . implode(' ', $command));

        $startTime = microtime(true);
        
        $process->run(function ($type, $buffer) {
            if ($type === Process::ERR) {
                Log::info("FFmpeg output: " . trim($buffer));
            }
        });

        $processingTime = round(microtime(true) - $startTime, 2);
        Log::info("مدة المعالجة: {$processingTime} ثانية");

        if (!$process->isSuccessful()) {
            $error = $process->getErrorOutput();
            $output = $process->getOutput();
            Log::error("فشل FFmpeg للدرس {$this->lesson->id}");
            Log::error("خطأ: " . $error);
            Log::error("المخرجات: " . $output);
            throw new ProcessFailedException($process);
        }

        Log::info("انتهت معالجة FFmpeg بنجاح للدرس {$this->lesson->id}");
    }

    /**
     * التحقق من نجاح المعالجة
     */
    private function verifyProcessing(string $outputDir): void
    {
        $playlistFile = "{$outputDir}/index.m3u8";

        if (!file_exists($playlistFile)) {
            throw new \Exception("لم يتم إنشاء ملف الـ playlist");
        }

        // التحقق من وجود ملفات segments
        $content = file_get_contents($playlistFile);
        if (empty($content)) {
            throw new \Exception("ملف الـ playlist فارغ");
        }

        // عد ملفات الـ segments
        $segmentCount = substr_count($content, '.ts');
        if ($segmentCount === 0) {
            throw new \Exception("لم يتم إنشاء أي مقاطع فيديو");
        }

        // التحقق من ملف المفتاح
        $keyFile = "{$outputDir}/enc.key";
        if (!file_exists($keyFile) || filesize($keyFile) !== 16) {
            throw new \Exception("ملف مفتاح التشفير غير صحيح");
        }

        Log::info("تم التحقق من صحة المعالجة للدرس {$this->lesson->id} - عدد المقاطع: {$segmentCount}");
    }

    /**
     * تنظيف الملفات في حالة الفشل
     */
    private function cleanup(): void
    {
        try {
            $outputDir = "private_videos/hls/lesson_{$this->lesson->id}";
            Storage::deleteDirectory($outputDir);
            Log::info("تم تنظيف الملفات للدرس {$this->lesson->id}");
        } catch (\Exception $e) {
            Log::error("خطأ في تنظيف الملفات للدرس {$this->lesson->id}: " . $e->getMessage());
        }
    }

    /**
     * معالجة الفشل
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("فشل نهائي في معالجة فيديو الدرس {$this->lesson->id}: " . $exception->getMessage());

        $this->lesson->update(['video_status' => 'failed']);
        $this->cleanup();
    }

    /**
     * إعادة المحاولة
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(4);
    }
}
