<dialog class="sku-advisor-dialog" data-sku-advisor>
    <div class="prompt-helper-header"><div><strong>AI 创作顾问</strong><small>用三个选择补充初稿，也可以直接采用 AI 识图结果。</small></div><button type="button" data-close-sku-advisor aria-label="关闭"><i data-lucide="x"></i></button></div>
    <div class="sku-advisor-body">
        <div class="advisor-chat-list" data-advisor-chat aria-live="polite"></div>
        <div class="advisor-options" data-advisor-options></div>
        <label class="advisor-custom-answer" data-advisor-custom hidden><span>补充你的想法</span><input class="input" maxlength="120" placeholder="输入后按确认"></label>
    </div>
    <div class="prompt-helper-footer"><button class="button" type="button" data-advisor-skip>跳过提问，直接采用初稿</button><button class="button primary" type="button" data-advisor-confirm hidden>确认补充</button></div>
</dialog>
