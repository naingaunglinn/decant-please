# CornerArea build run — စတင်နည်း

Claude Code ကို `prompts/RUN-QUEUE.md` ထဲက item ၃၁ ခုကို တစ်ခုပြီးတစ်ခု ဆောက်ခိုင်းပြီး
PR တွေ stack လိုက် တင်ခိုင်းမယ်။ Merge ကို Claude ဘယ်တော့မှ မလုပ်ဘူး၊ မင်းပဲ လုပ်မယ်။

## တစ်ခါပဲ ပြင်ဆင်ရမှာ

1. `claude update` — `--permission-prompts` flag အတွက် Claude Code v2.1.259 နဲ့ အထက် လိုတယ်။
2. Repo မှာ `git checkout 110-drop-is-studio-flag && git pull` လုပ်ပြီး ဒီ bundle ကို repo root မှာ ဖြည်ပါ။
   Path တွေ (`.claude/`, `prompts/`, `scripts/`) အတိုင်း ဝင်သွားမယ်။ Commit မလုပ်နဲ့၊
   row 0 (item 0) က commit လုပ်ပေးမယ်။
3. GitHub → Settings:
   - Branch protection ကို `main` နဲ့ `develop` နှစ်ခုလုံးမှာ ထားပါ: PR မဖြစ်မနေ၊ force push ပိတ်။
   - "Allow merge commits" ဖွင့်ထားပါ။ Stack PR တွေကို squash merge မလုပ်ပါနဲ့။
   - "Automatically delete head branches" ဖွင့်ပါ။ Merge ပြီးရင် အပေါ်က PR ကို GitHub က
     `develop` ဆီ အလိုလို ပြောင်းပေးမယ်။
4. Production ကို ဒီစက်ကနေ ထိလို့မရအောင် လုပ်ပါ: `heroku logout`၊ `.env` ထဲမှာ production
   DB URL မထားပါနဲ့။ Deny rule တွေက ကာကွယ်ပေးပေမဲ့ security boundary အစစ် မဟုတ်ဘူး၊
   credential မရှိတာက အစစ်ပါ။
5. `docker compose up -d` (test အတွက် Postgres)။

## စ run မယ်

```bash
bash scripts/run-queue.sh
```

- စက်ကို အိပ်မသွားအောင် ထားပါ (Mac ဆို `caffeinate -i bash scripts/run-queue.sh`)။
- Run တစ်ခုစီရဲ့ log က `.run-logs/` ထဲမှာ ရှိမယ်။
- ရပ်ချင်ရင်: `touch STOP` (လက်ရှိ item ပြီးမှ ရပ်မယ်) ဒါမှမဟုတ် Ctrl-C။
- Usage limit ပြည့်ရင် script က ရပ်သွားမယ်။ နောက်မှ ပြန် run ရင် ရပ်ခဲ့တဲ့နေရာကနေ ဆက်မယ်။

## Review လုပ်နည်း

- **အောက်ဆုံး PR ကနေ စစ်ပါ။** Run ပြီးဆုံးတာကို စောင့်စရာ မလိုဘူး၊ run နေတုန်း စစ်လို့ရတယ်။
  စောစောစစ်လေ နောက်ပိုင်း ပြန်ပြင်ရတာ နည်းလေပဲ။
- PR တိုင်းရဲ့ "Review guide" မှာ risk high/medium/low နဲ့ အရင်ကြည့်ရမယ့်နေရာ ရေးထားမယ်။
  **High** (ငွေ၊ tenancy၊ migration၊ auth) ကို သေချာ စစ်ပါ။ **Low** ကို အမြန်ကြည့်ရုံပဲ။
- "Decisions I made — check these" section ကို အမြဲ ဖတ်ပါ။ Spec မှာ မပါတာကို Claude
  ကိုယ်တိုင် ဆုံးဖြတ်ထားတာတွေ ဖြစ်တယ်။
- **ပြင်ခိုင်းချင်ရင်:** PR မှာ comment ရေးပြီး `fix` label ထည့်ပါ။ နောက် run က အဲ့ဒါကို
  အရင်ပြင်ပြီး အပေါ်က PR တွေဆီ merge နဲ့ ဆင့်ပို့ပေးမယ်။
- **Merge:** အောက်ကနေ အပေါ်ကို "Create a merge commit" နဲ့။
- `needs-owner` item (Plans) နဲ့ `after-go-live` item (rename) ကို run က ကျော်သွားမယ်။
  ဆုံးဖြတ်ပြီးရင် `RUN-QUEUE.md` မှာ `todo` လို့ ပြောင်းပေးပါ။
