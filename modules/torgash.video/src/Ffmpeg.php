<?php

namespace Torgash\Video;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * ffmpeg and ffprobe, when the server has them (PLAN §50, step 4).
 *
 * Nothing here is required: without ffmpeg a file is played exactly as it
 * was uploaded and the admin is asked for a poster. Where to find it is the
 * module's setting (`ffmpeg_path` — the program or its folder); empty means
 * "ffmpeg" on the PATH of whoever runs it, which for the web server and for
 * the queue worker need not be the same PATH — hence the setting.
 *
 * Always an argument list, never a shell line: a file name is the admin's.
 */
class Ffmpeg
{
    /** The ffmpeg program to run, as the setting names it. */
    public function binary(): string
    {
        return $this->program('ffmpeg');
    }

    public function probeBinary(): string
    {
        return $this->program('ffprobe');
    }

    /** Both programs answer. Not remembered: the setting can change between two calls of a worker. */
    public function available(): bool
    {
        foreach ([$this->binary(), $this->probeBinary()] as $program) {
            try {
                $process = new Process([$program, '-version']);
                $process->setTimeout(10);
                $process->run();

                if (! $process->isSuccessful()) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * What a file is: its length, the picture's size AS SHOWN (a phone's
     * portrait clip is stored wide and turned by a rotation mark — ffmpeg
     * turns it while converting, so the turned size is what counts), and the
     * codecs.
     *
     * @return array{duration: ?float, width: int, height: int, video: ?string, audio: ?string, format: string}
     *
     * @throws RuntimeException when ffprobe cannot read it
     */
    public function probe(string $file): array
    {
        $process = new Process([
            $this->probeBinary(), '-v', 'error',
            '-show_entries', 'stream=codec_type,codec_name,width,height:stream_side_data=rotation:stream_tags=rotate:format=duration,format_name',
            '-of', 'json', $file,
        ]);
        $process->setTimeout(60);
        $process->run();

        $data = json_decode($process->getOutput(), true);

        if (! $process->isSuccessful() || ! is_array($data)) {
            throw new RuntimeException(self::lastLine($process->getErrorOutput()) ?: 'ffprobe could not read the file.');
        }

        $video = null;
        $audio = null;

        foreach ((array) ($data['streams'] ?? []) as $stream) {
            if (($stream['codec_type'] ?? '') === 'video' && $video === null) {
                $video = $stream;
            } elseif (($stream['codec_type'] ?? '') === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        if ($video === null) {
            throw new RuntimeException('There is no picture in the file.');
        }

        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);
        $rotation = (int) ($video['tags']['rotate'] ?? 0);

        foreach ((array) ($video['side_data_list'] ?? []) as $side) {
            if (isset($side['rotation'])) {
                $rotation = (int) $side['rotation'];
            }
        }

        if (abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $duration = $data['format']['duration'] ?? null;

        return [
            'duration' => is_numeric($duration) ? (float) $duration : null,
            'width' => $width,
            'height' => $height,
            'video' => isset($video['codec_name']) ? (string) $video['codec_name'] : null,
            'audio' => isset($audio['codec_name']) ? (string) $audio['codec_name'] : null,
            'format' => (string) ($data['format']['format_name'] ?? ''),
        ];
    }

    /**
     * One frame at `$second` as a JPEG — the poster of a file of the shop's own.
     *
     * @throws RuntimeException
     */
    public function frame(string $file, float $second, string $jpeg): void
    {
        $process = new Process([
            $this->binary(), '-hide_banner', '-nostdin', '-y',
            '-ss', sprintf('%.3F', max(0, $second)), '-i', $file,
            '-frames:v', '1', '-q:v', '2', $jpeg,
        ]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($jpeg) || filesize($jpeg) === 0) {
            throw new RuntimeException(self::lastLine($process->getErrorOutput()) ?: 'ffmpeg took no frame.');
        }
    }

    /**
     * Moves the index (`moov`) to the front without touching the picture —
     * seconds, not minutes. Without it the browser cannot start playing, nor
     * jump to the middle, before it has fetched the file's END.
     *
     * @throws RuntimeException
     */
    public function faststart(string $in, string $out): void
    {
        $this->run([
            $this->binary(), '-hide_banner', '-nostdin', '-y', '-i', $in,
            // Picture and sound only: a camera's data track (GoPro's GPS)
            // is what a copy into mp4 refuses.
            '-map', '0:v', '-map', '0:a?', '-c', 'copy', '-movflags', '+faststart', '-nostats', $out,
        ], null, null);
    }

    /**
     * Into what every browser plays: H.264 + AAC in mp4, the index in front,
     * the SHORT side at most `$maxSide` (1080 is "1080p" for a wide clip and
     * for a phone's tall one alike). `$onSecond` hears how far it has got,
     * in seconds of the video, and may throw to stop it.
     *
     * @param  Closure(float): void|null  $onSecond
     *
     * @throws RuntimeException
     */
    public function web(string $in, string $out, int $maxSide, ?Closure $onSecond = null): void
    {
        // -2 keeps the other side even (H.264 wants that) and in proportion.
        $scale = "scale='if(gte(iw,ih),-2,min(iw,{$maxSide}))':'if(gte(iw,ih),min(ih,{$maxSide}),-2)'";

        $this->run([
            $this->binary(), '-hide_banner', '-nostdin', '-y', '-i', $in,
            '-map', '0:v:0', '-map', '0:a:0?',
            '-vf', $scale,
            '-c:v', 'libx264', '-preset', 'medium', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart',
            '-progress', 'pipe:1', '-nostats',
            $out,
        ], $onSecond, null);
    }

    /**
     * Whether an mp4's index stands before its data. Read from the file's
     * top-level boxes, not asked of ffmpeg: it is the one fact ffprobe does
     * not print.
     */
    public static function indexFirst(string $file): bool
    {
        $handle = @fopen($file, 'rb');

        if (! $handle) {
            return false;
        }

        try {
            $size = (int) filesize($file);
            $offset = 0;

            while ($offset + 8 <= $size) {
                fseek($handle, $offset);
                $head = (string) fread($handle, 16);

                if (strlen($head) < 8) {
                    return false;
                }

                $length = unpack('N', substr($head, 0, 4))[1];
                $type = substr($head, 4, 4);

                if ($length === 1 && strlen($head) === 16) {
                    $high = unpack('N', substr($head, 8, 4))[1];
                    $low = unpack('N', substr($head, 12, 4))[1];
                    $length = $high * 4294967296 + $low;
                } elseif ($length === 0) {
                    $length = $size - $offset;
                }

                if ($type === 'moov') {
                    return true;
                }

                if ($type === 'mdat' || $length < 8) {
                    return false;
                }

                $offset += $length;
            }

            return false;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string>  $command
     * @param  Closure(float): void|null  $onSecond
     *
     * @throws RuntimeException
     */
    protected function run(array $command, ?Closure $onSecond, ?int $timeout): void
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->start();

        $buffer = '';

        try {
            while ($process->isRunning()) {
                usleep(200_000);
                $buffer .= $process->getIncrementalOutput();

                if ($onSecond && preg_match_all('/^out_time_us=(\d+)$/m', $buffer, $found)) {
                    $buffer = '';
                    $onSecond((int) end($found[1]) / 1_000_000);
                }
            }
        } catch (Throwable $e) {
            $process->stop(2);

            throw $e;
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(self::lastLine($process->getErrorOutput()) ?: 'ffmpeg failed.');
        }
    }

    /** ffmpeg's own last word — what went wrong, without the banner above it. */
    protected static function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: [])));

        return mb_substr((string) end($lines), 0, 500);
    }

    protected function program(string $name): string
    {
        $setting = trim((string) setting('torgash.video', 'ffmpeg_path', ''), " \t\"'");

        if ($setting === '') {
            return $name;
        }

        $windows = PHP_OS_FAMILY === 'Windows';

        // A folder: the program inside it.
        if (is_dir($setting)) {
            return rtrim($setting, '/\\').DIRECTORY_SEPARATOR.$name.($windows ? '.exe' : '');
        }

        // The ffmpeg program itself: ffprobe stands beside it.
        if ($name === 'ffmpeg') {
            return $setting;
        }

        $folder = dirname($setting);
        $base = preg_replace('/ffmpeg(?=(\.exe)?$)/i', 'ffprobe', basename($setting));

        return ($folder === '.' ? '' : $folder.DIRECTORY_SEPARATOR).$base;
    }
}
