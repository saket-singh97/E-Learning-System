<div
    x-data="{
        speed: 1,
        watermarkTop: 5,
        watermarkLeft: 5,
        resumeAt: {{ $resumeAt }},
        lastReported: {{ $resumeAt }},
        reposition() {
            this.watermarkTop = Math.floor(Math.random() * 70) + 5;
            this.watermarkLeft = Math.floor(Math.random() * 70) + 5;
        },
        init() {
            this.$refs.video.volume = 0.7;
            this.reposition();
            setInterval(() => this.reposition(), 30000);
            setInterval(() => this.reportHeartbeat(), 10000);
        },
        onLoadedMetadata() {
            if (this.resumeAt > 0) {
                this.$refs.video.currentTime = this.resumeAt;
            }
        },
        reportHeartbeat() {
            if (this.$refs.video.paused) return;

            const end = Math.floor(this.$refs.video.currentTime);
            if (end <= this.lastReported) return;

            const duration = this.$refs.video.duration;

            fetch('{{ $heartbeatUrl }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    start_s: this.lastReported,
                    end_s: end,
                    duration_s: Number.isFinite(duration) ? Math.floor(duration) : null,
                }),
            });

            this.lastReported = end;
        },
        seek(seconds) {
            this.$refs.video.currentTime = Math.max(0, this.$refs.video.currentTime + seconds);
        },
        togglePlay() {
            this.$refs.video.paused ? this.$refs.video.play() : this.$refs.video.pause();
        },
        setSpeed(value) {
            this.speed = value;
            this.$refs.video.playbackRate = value;
        },
        toggleFullscreen() {
            document.fullscreenElement ? document.exitFullscreen() : this.$refs.container.requestFullscreen();
        },
    }"
    x-ref="container"
    x-on:seek-to.window="$refs.video.currentTime = $event.detail.seconds"
    class="relative bg-black"
>
    {{--
        Content protection (S9 B6, design doc D5): controlsList="nodownload"
        hides the browser's own download button, and disabling the context
        menu on just this element stops the easiest "Save video as..." path.
        Neither stops a determined screen-recorder — same honest framing
        this app's own watermark feature (S2) already uses for its limits,
        not a claim of real DRM.
    --}}
    <video
        x-ref="video"
        src="{{ $streamUrl }}"
        controls
        controlsList="nodownload"
        oncontextmenu="return false"
        x-on:loadedmetadata="onLoadedMetadata()"
        class="w-full"
    ></video>

    <div
        x-ref="watermark"
        class="pointer-events-none select-none absolute text-white/70 text-sm px-2 py-1"
        :style="`top: ${watermarkTop}%; left: ${watermarkLeft}%;`"
    >{{ $viewerName }} — {{ $viewerEmail }}</div>

    <div class="player-controls flex items-center gap-2 p-2">
        <button type="button" x-on:click="seek(-10)">-10s</button>
        <button type="button" x-on:click="togglePlay()">Play / Pause</button>
        <button type="button" x-on:click="seek(10)">+10s</button>

        <select x-model="speed" x-on:change="setSpeed($event.target.value)">
            <option value="0.5">0.5x</option>
            <option value="1" selected>1x</option>
            <option value="1.5">1.5x</option>
            <option value="2">2x</option>
        </select>

        <button type="button" x-on:click="toggleFullscreen()">Fullscreen</button>
    </div>
</div>
