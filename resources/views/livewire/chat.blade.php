<div x-data="chatWidget(@js($messages))" class="flex flex-col" style="height: calc(100vh - 10rem);">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="h-display text-2xl text-bone-50">NRL Analyst Chat</h1>
            <p class="text-sm text-bone-400">Ask about try scorers, match predictions, player stats, injuries & more</p>
        </div>
        <button x-show="chatMessages.length > 0" x-cloak @click="clearAll()" class="btn-ghost text-xs">Clear chat</button>
    </div>

    <div class="card flex-1 overflow-y-auto p-4 space-y-4" x-ref="chatMessages">
        <template x-if="chatMessages.length === 0 && !loading">
            <div class="flex flex-col items-center justify-center h-full text-center text-bone-400 space-y-4">
                <div class="grid h-14 w-14 place-items-center rounded-full bg-gold-500/10">
                    <svg class="h-7 w-7 text-gold-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                    </svg>
                </div>
                <div>
                    <div class="text-bone-200 font-medium mb-1">Ask me anything about NRL</div>
                    <div class="text-xs space-y-1">
                        <div>"Who are the top try scorer picks for Broncos vs Storm?"</div>
                        <div>"Which teams have the most injuries this round?"</div>
                        <div>"Build me a 4-leg multi for this weekend"</div>
                        <div>"How does Alex Johnston go at Accor Stadium?"</div>
                    </div>
                </div>
            </div>
        </template>

        <template x-for="(msg, idx) in chatMessages" :key="idx">
            <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                <div class="max-w-[80%] rounded-lg px-4 py-3 text-sm leading-relaxed"
                     :class="msg.role === 'user'
                        ? 'bg-gold-500/20 text-bone-50 border border-gold-500/30'
                        : 'bg-ink-700 text-bone-100 border border-ink-600'">
                    <template x-if="msg.role === 'assistant'">
                        <div class="prose prose-sm prose-invert max-w-none [&_ul]:list-disc [&_ul]:pl-4 [&_ol]:list-decimal [&_ol]:pl-4 [&_li]:my-0.5 [&_p]:my-1.5 [&_strong]:text-gold-400 [&_h3]:text-base [&_h3]:text-bone-50 [&_h3]:mt-3 [&_h3]:mb-1"
                             x-html="renderMarkdown(msg.content)"></div>
                    </template>
                    <template x-if="msg.role === 'user'">
                        <span x-text="msg.content"></span>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="loading">
            <div class="flex justify-start">
                <div class="bg-ink-700 border border-ink-600 rounded-lg px-4 py-3">
                    <div class="flex items-center gap-2 text-sm text-bone-400">
                        <div class="flex gap-1">
                            <span class="h-2 w-2 rounded-full bg-gold-500 animate-bounce" style="animation-delay: 0ms; animation-duration: 1s"></span>
                            <span class="h-2 w-2 rounded-full bg-gold-500 animate-bounce" style="animation-delay: 200ms; animation-duration: 1s"></span>
                            <span class="h-2 w-2 rounded-full bg-gold-500 animate-bounce" style="animation-delay: 400ms; animation-duration: 1s"></span>
                        </div>
                        <span x-text="thinkingText" class="text-xs"></span>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <form @submit.prevent="sendMessage" class="mt-4 flex gap-2">
        <input type="text"
               x-model="inputText"
               placeholder="Ask about try scorers, match predictions, player stats..."
               class="flex-1 rounded-lg border border-ink-600 bg-ink-800 px-4 py-3 text-sm text-bone-50 placeholder-bone-500
                      focus:border-gold-500 focus:outline-none focus:ring-1 focus:ring-gold-500"
               :disabled="loading"
               autocomplete="off">
        <button type="submit"
                class="btn-primary px-6"
                :disabled="loading || !inputText.trim()">
            <span x-show="!loading">Send</span>
            <span x-show="loading" x-cloak>...</span>
        </button>
    </form>
</div>

<script>
function chatWidget(initial) {
    return {
        chatMessages: initial || [],
        inputText: '',
        loading: false,
        thinkingText: 'Analysing NRL data...',
        thinkingMessages: [
            'Analysing NRL data...',
            'Checking match predictions...',
            'Reviewing player stats...',
            'Crunching the numbers...',
            'Consulting the signals...',
            'Almost there...',
        ],
        thinkingInterval: null,

        renderMarkdown(text) {
            // Basic markdown: bold, bullets, newlines
            let html = text
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                .replace(/^### (.+)$/gm, '<h3>$1</h3>')
                .replace(/^- (.+)$/gm, '<li>$1</li>')
                .replace(/^(\d+)\. (.+)$/gm, '<li>$2</li>')
                .replace(/\n/g, '<br>');
            // Wrap consecutive <li> in <ul>
            html = html.replace(/((<li>.*?<\/li>(<br>)?)+)/g, '<ul>$1</ul>');
            html = html.replace(/<ul>(.*?)<\/ul>/gs, (m, inner) => '<ul>' + inner.replace(/<br>/g, '') + '</ul>');
            return html;
        },

        sendMessage() {
            const text = this.inputText.trim();
            if (!text || this.loading) return;

            // Add user message immediately (client-side only)
            this.chatMessages.push({ role: 'user', content: text });
            const userText = text;
            this.inputText = '';
            this.loading = true;

            // Start cycling thinking text
            let i = 0;
            this.thinkingText = this.thinkingMessages[0];
            this.thinkingInterval = setInterval(() => {
                i = (i + 1) % this.thinkingMessages.length;
                this.thinkingText = this.thinkingMessages[i];
            }, 3000);

            this.$nextTick(() => this.scrollToBottom());

            // Build history (everything except the message we just added)
            const history = this.chatMessages.slice(0, -1).map(m => ({
                role: m.role,
                content: m.content
            }));

            // Async fetch — UI stays responsive
            fetch('/api/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ message: userText, history: history }),
            })
            .then(res => res.json())
            .then(data => {
                clearInterval(this.thinkingInterval);
                this.thinkingInterval = null;
                const reply = (data.ok && data.reply)
                    ? data.reply
                    : (data.error || 'Sorry, I encountered an error. Please try again.');
                this.chatMessages.push({ role: 'assistant', content: reply });
                this.loading = false;
                // Sync to Livewire for persistence
                this.$wire.set('messages', this.chatMessages);
                this.$nextTick(() => this.scrollToBottom());
            })
            .catch(err => {
                clearInterval(this.thinkingInterval);
                this.thinkingInterval = null;
                this.chatMessages.push({ role: 'assistant', content: 'Failed to reach the AI service. Is it running?' });
                this.loading = false;
                this.$wire.set('messages', this.chatMessages);
                this.$nextTick(() => this.scrollToBottom());
            });
        },

        clearAll() {
            this.chatMessages = [];
            this.loading = false;
            this.inputText = '';
            clearInterval(this.thinkingInterval);
            this.$wire.set('messages', []);
        },

        scrollToBottom() {
            const el = this.$refs.chatMessages;
            if (el) el.scrollTop = el.scrollHeight;
        }
    };
}
</script>
