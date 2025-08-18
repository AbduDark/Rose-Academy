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
            Log::info('Starting video processing for lesson: ' . $this->lesson->id);

            $videoPath = storage_path('app/private_videos/original_videos/lesson_' . $this->lesson->id . '_video.' . $this->extension);

            if (!file_exists($videoPath)) {
                throw new \Exception('Video file not found: ' . $videoPath);
            }

            Log::info('Video file found: ' . $videoPath);

            // تحديث التقدم إلى 10%
            $this->lesson->update(['video_processing_progress' => 10]);

            // إنشاء مجلد HLS للدرس
            $hlsDir = storage_path('app/private_videos/hls/lesson_' . $this->lesson->id);
            if (!is_dir($hlsDir)) {
                mkdir($hlsDir, 0755, true);
            }

            // تحديث التقدم إلى 20%
            $this->lesson->update(['video_processing_progress' => 20]);

            // تشفير الفيديو إلى HLS
            $keyFile = $hlsDir . '/key.key';
            $keyInfoFile = $hlsDir . '/key.keyinfo';
            $playlistFile = $hlsDir . '/index.m3u8';

            // إنشاء مفتاح التشفير
            $encryptionKey = bin2hex(random_bytes(16));
            file_put_contents($keyFile, hex2bin($encryptionKey));

            // تحديث التقدم إلى 30%
            $this->lesson->update(['video_processing_progress' => 30]);

            // إنشاء ملف معلومات المفتاح
            $keyInfo = "key.key\n" . asset('api/lesson/' . $this->lesson->id . '/encryption-key') . "\n" . $encryptionKey;
            file_put_contents($keyInfoFile, $keyInfo);

            // تحديث التقدم إلى 40%
            $this->lesson->update(['video_processing_progress' => 40]);

            Log::info('Starting FFmpeg conversion for lesson: ' . $this->lesson->id);

            // تحويل الفيديو باستخدام FFmpeg
            $ffmpegCommand = "ffmpeg -i \"$videoPath\" -hls_time 10 -hls_key_info_file \"$keyInfoFile\" -hls_playlist_type vod -hls_segment_filename \"$hlsDir/segment_%03d.ts\" \"$playlistFile\"";

            // تحديث التقدم إلى 50%
            $this->lesson->update(['video_processing_progress' => 50]);

            exec($ffmpegCommand . ' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                Log::error('FFmpeg failed: ' . implode("\n", $output));
                throw new \Exception('FFmpeg failed: ' . implode("\n", $output));
            }

            // تحديث التقدم إلى 80%
            $this->lesson->update(['video_processing_progress' => 80]);

            Log::info('FFmpeg conversion completed for lesson: ' . $this->lesson->id);

            // تحديث بيانات الدرس
            $this->lesson->update([
                'video_status' => 'processed',
                'video_processing_progress' => 100,
                'video_encryption_key' => $encryptionKey,
                'video_hls_path' => 'private_videos/hls/lesson_' . $this->lesson->id . '/index.m3u8'
            ]);

            Log::info('Video processing completed successfully for lesson: ' . $this->lesson->id);

            // حذف الفيديو الأصلي لتوفير المساحة
            if (file_exists($videoPath)) {
                unlink($videoPath);
                Log::info('Original video file deleted for lesson: ' . $this->lesson->id);
            }

        } catch (\Exception $e) {
            Log::error('Video processing failed for lesson: ' . $this->lesson->id . ' - Error: ' . $e->getMessage());

            // تحديث حالة الخطأ
            $this->lesson->update([
                'video_status' => 'failed',
                'video_processing_progress' => 0
            ]);

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