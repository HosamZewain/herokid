#!/usr/bin/env python3
"""Render the synthetic classifier corpus/results as a portable Arabic review."""
import argparse
import hashlib
import html
import json
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--report', type=Path)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
source = Path(__file__).parents[2] / 'tests/Fixtures/admin-assistant/questions.ar.json'
corpus = json.loads(source.read_text())
digest = hashlib.sha256(source.read_bytes()).hexdigest()
report = json.loads(args.report.read_text()) if args.report else None
if report and report['dataset_sha256'] != digest:
    raise SystemExit('Report belongs to a different corpus')
results = {r['id']: r for r in report['results']} if report else {}
labels = {'help': 'شرح إمكانيات المساعد', 'order': 'قراءة أوردر واحد', 'queue': 'الطلبات النشطة في نطاق المستخدم',
          'autopilot': 'ملخص Auto Pilot', 'sales': 'ملخص المبيعات', 'payments': 'حركات الدفعات المسجلة',
          'unsupported': 'توضيح أن السؤال غير مدعوم أو غير مسموح'}
periods = {'page': 'فلاتر الصفحة الحالية', 'today': 'اليوم', 'yesterday': 'أمس', 'last_7_days': 'آخر 7 أيام',
           'last_30_days': 'آخر 30 يومًا', 'this_month': 'الشهر الحالي', 'last_month': 'الشهر السابق',
           'this_year': 'السنة الحالية', 'custom': 'فترة محددة'}
def describe(plan):
    if not plan:
        return 'لم تتم القراءة'
    parts = [labels.get(plan['tool'], plan['tool'])]
    if plan['tool'] == 'order':
        parts.append('رقم ' + (plan['reference'] or 'الأوردر المفتوح، أو طلب توضيح لو غير موجود'))
    if plan['tool'] in ['payments', 'sales']:
        parts.append(periods[plan['range']])
        if plan['range'] == 'custom': parts.append(str(plan['start_date']) + ' ← ' + str(plan['end_date']))
    if plan.get('intent') == 'execute': parts.append('توضيح أنه لم ينفذ، مع رابط الإجراء الأصلي')
    return ' · '.join(parts)
rows = []
for i, case in enumerate(corpus, 1):
    result = results.get(case['id'])
    page = case['input']['page']
    context = 'صفحة: ' + labels.get(page['tool'], page['tool'])
    if page.get('reference'): context += ' / ' + page['reference']
    if case['input'].get('previous_reference'): context += '؛ الأوردر السابق: ' + case['input']['previous_reference']
    context += '؛ المصادر المتاحة: ' + '، '.join(labels[t] for t in case['input']['available_tools'])
    outcome = 'لم يختبر بعد' if not result else ('مطابق للتوقع' if result['outcome'] == 'passed' else 'يحتاج مراجعة: ' + result['outcome'])
    actual = describe(result['actual']) if result else '—'
    rows.append(f'<tr data-case="{html.escape(case["id"])}"><td>{i}</td><td><strong>{html.escape(case["input"]["question"])}</strong><small>{html.escape(context)}</small></td><td>{html.escape(describe(case["expected"]))}</td><td>{html.escape(actual)}<small>{html.escape(outcome)}</small></td><td><select aria-label="حكم الحالة {i}"><option value="pending">لم أراجع</option value="correct">صح</option><option value="incorrect">غلط</option><option value="edit">يحتاج تعديل</option></select><textarea aria-label="تعديل الحالة {i}" placeholder="اكتب القرار الصحيح أو ملاحظتك"></textarea></td></tr>')
summary = 'عينة اصطناعية للتطوير؛ لم تعتمد من صاحب المشروع بعد.'
if report: summary += f' نتيجة الموديل: {report["passed"]}/{report["total"]} مطابق للتوقع؛ {report["unevaluated"]} غير مقيم؛ {len(report["critical_failures"])} إخفاقات حرجة.'
page = '''<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>مراجعة فهم مساعد HeroKid</title><style>body{font-family:Tahoma,Arial,sans-serif;background:#f5f7fc;color:#1e293b;margin:0;padding:28px;line-height:1.8}header{max-width:1400px;margin:auto}h1{margin:0;color:#3730a3}p{margin:8px 0}button{background:#4338ca;color:white;padding:12px 20px;border:0;border-radius:10px;cursor:pointer;font:inherit}.table{overflow:auto;margin-top:24px}table{border-collapse:collapse;background:white;width:100%;min-width:950px}th,td{text-align:right;border:1px solid #dce1eb;padding:16px;vertical-align:top}th{background:#eef2ff}small{display:block;color:#64748b;font-size:12px;margin-top:10px}textarea,select{box-sizing:border-box;width:100%;min-width:160px;font:inherit;border:1px solid #cbd5e1;border-radius:8px;padding:8px}textarea{height:100px;margin-top:8px}code{direction:ltr;display:inline-block;font-size:10px;overflow-wrap:anywhere}#count{margin-inline:16px}</style><header><h1>مراجعة فهم مساعد HeroKid</h1><p>SUMMARY</p><p>اقرأ السؤال وسياقه والرد المتوقع، ثم اختر صح أو غلط أو اكتب تعديلك. النتيجة تقيس اختيار مصدر القراءة، وليست دقة تقارير الإنتاج أو صلاحية تنفيذ الأوردرات.</p><p>كل البيانات هنا اصطناعية. حفظ المراجعة لا ينشر الشات ولا يفعّل التنفيذ.</p><button id="save">حفظ نسخة بإجاباتي</button><span id="count"></span><small>بصمة العينة <code>DIGEST</code></small></header><div class="table"><table><thead><tr><th>#</th><th>السؤال والسياق</th><th>المتوقع</th><th>نتيجة الموديل</th><th>رأيك والتعديل</th></tr></thead><tbody>ROWS</tbody></table></div><script>
function update(){document.querySelector('#count').textContent='تمت مراجعة '+[...document.querySelectorAll('select')].filter(x=>x.value!=='pending').length+' / '+document.querySelectorAll('select').length;}
document.addEventListener('change',update);update();
document.querySelector('#save').onclick=()=>{const clone=document.documentElement.cloneNode(true);document.querySelectorAll('select').forEach((s,i)=>{const target=clone.querySelectorAll('select')[i];[...target.options].forEach(o=>o.toggleAttribute('selected',o.value===s.value));});document.querySelectorAll('textarea').forEach((t,i)=>clone.querySelectorAll('textarea')[i].textContent=t.value);const url=URL.createObjectURL(new Blob(['<!doctype html>'+clone.outerHTML],{type:'text/html;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download='herokid-assistant-review-with-answers.html';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);};
</script></html>'''
args.output.parent.mkdir(parents=True, exist_ok=True)
args.output.write_text(page.replace('SUMMARY',html.escape(summary)).replace('DIGEST',digest).replace('ROWS','\n'.join(rows)))
print(args.output)
