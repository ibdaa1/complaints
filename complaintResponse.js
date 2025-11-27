// complaintResponse.js
// يضيف ملء تلقائي لحقل response_speed (سرعة الاستجابة) بناءً على قيمة complaint_category
// - يعرّف دالة complaintUrgency نفسها (مطابقة للقائمة التي أرسلتها)
// - ينشئ عنصر عرضي (readonly) لعرض السرعة المحسوبة بجانب select التصنيف
// - ينشئ حقل مخفي <input id="response_speed"> ليُرسَل مع طلب الحفظ
// - يحدث الحقل عند تغيير التصنيف وعند تحميل النموذج/فتح شكوى
// - طريقة الإدراج: أضف <script src="complaintResponse.js"></script> أسفل بقية سكربتات الصفحة (قبل إغلاق </body>).
//
// ملاحظة: هذا ملف عميل (JS) فقط — يجب أن يكون complaints.php يقبل حقل response_speed إن أردت حفظه في قاعدة البيانات.
// إذا لم يكن العمود موجوداً على الخادم فستبقى القيمة مرسلة لكن لن تُخزن إلا بعد تعديل السيرفر (اختياري).

(function(){
  // دالة تحسب سرعة الاستجابة بناء على التصنيف
  function complaintUrgency(category) {
    if (!category) return "غير محددة";
    switch(category) {
      case "النظافة العامة":
      case "طرق اعداد وحفظ":
      case "الغش التجارى":
      case "الحشرات والقوارض":
      case "المخالفات العامة":
      case "مخالفات سوق الخضار والسمك":
      case "مخالفات قسم البيطرة":
      case "اشتباة تسمم غذائى بدون تقرير مستشفى":
        return "عاجلة خلال يوم عمل واحد";
      case "المبانى والمنشات":
        return "عادية خلال خمس أيام عمل";
      case "اشتباة تسمم غذائى":
      case "إجراءات احترازية":
        return "طارئة استجابة فورية";
      case "شكوى تحتاج الى الاشتراك مع جهات اخرى":
        return "معقدة خلال فترة غير محددة";
      default:
        return "غير محددة";
    }
  }

  // مساعدة لايجاد عنصر وإرجاعه
  function $id(id){ return document.getElementById(id); }

  // إنشاء حقول العرض والحقل المخفي إذا لم تكن موجودة
  function ensureResponseSpeedElements() {
    let display = $id('response_speed_display');
    if (!display) {
      // نضيف حقل عرضي بعد select التصنيف إن وجد
      const sel = $id('complaint_category');
      if (sel && sel.parentNode) {
        display = document.createElement('input');
        display.type = 'text';
        display.id = 'response_speed_display';
        display.readOnly = true;
        display.style.marginTop = '6px';
        display.placeholder = 'سرعة الاستجابة تلقائياً';
        // وضع العرض أسفل select
        sel.parentNode.appendChild(display);
      }
    }

    let hidden = $id('response_speed');
    if (!hidden) {
      hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.id = 'response_speed';
      // نضيفه داخل form الشكوى إن وُجد
      const form = $id('complaintForm') || document.querySelector('form');
      if (form) form.appendChild(hidden);
      else document.body.appendChild(hidden);
    }
    return { display: display || $id('response_speed_display'), hidden: hidden || $id('response_speed') };
  }

  // حدث تغيير التصنيف -> تحديث السرعة
  function onCategoryChange() {
    const sel = $id('complaint_category');
    if (!sel) return;
    const cat = (sel.value || '').trim();
    const speed = complaintUrgency(cat);
    const els = ensureResponseSpeedElements();
    if (els.display) els.display.value = speed;
    if (els.hidden) els.hidden.value = speed;
  }

  // عند فتح نموذج موجود أو عند تحميل الصفحة: إذا كان هناك قيمة موجودة في الحقل response_speed من الخادم اتركها،
  // وإلا احسبها من القيمة الحالية للتصنيف.
  function syncResponseSpeedOnLoad() {
    const hidden = $id('response_speed');
    const sel = $id('complaint_category');
    const display = $id('response_speed_display');
    // إذا كان الحقل المخفي موجود وله قيمة (ربما ملئه الخادم عند فتح الشكوى) نعرضها
    if (hidden && hidden.value) {
      if (!display) ensureResponseSpeedElements();
      if ($id('response_speed_display')) $id('response_speed_display').value = hidden.value;
      return;
    }
    // وإلا حسب من التصنيف الحالي
    onCategoryChange();
  }

  // نتأكد من أن القيمة تُحدّث قبل إرسال الحفظ (في حال كانت صيغة الحفظ تجمع الحقول يدوياً)
  // نحن فقط نحدد الحقل المخفي response_speed أيضاً قبل النقر على حفظ
  function hookSaveButtons() {
    // الأزرار المحتملة باسم btnSave أو غيره
    const candidates = ['btnSave','btnSaveSupervisor','btnSaveManager','btnSaveProducts'];
    candidates.forEach(id=>{
      const el = $id(id);
      if (el && !el._responseSpeedHooked) {
        el.addEventListener('click', () => {
          // تحديث الحقل المخفي بالقيمة الحالية
          const sel = $id('complaint_category');
          const speed = complaintUrgency((sel && sel.value) || '');
          const els = ensureResponseSpeedElements();
          if (els.display) els.display.value = speed;
          if (els.hidden) els.hidden.value = speed;
        });
        el._responseSpeedHooked = true;
      }
    });
  }

  // ربط الحدث عند DOMContentLoaded
  document.addEventListener('DOMContentLoaded', () => {
    // إنشاء عناصر إن لم تكن موجودة
    ensureResponseSpeedElements();

    // ربط حدث التغيير على select التصنيف
    const sel = $id('complaint_category');
    if (sel) sel.addEventListener('change', onCategoryChange);

    // ربط حفظ الأزرار للتأكد الحقل مُحدث
    hookSaveButtons();

    // مزامنة مبدئية
    syncResponseSpeedOnLoad();

    // إذا كانت الصفحة تتبع آلية تحميل الشكوى عبر JS بعد تحميل الصفحة (openComplaint) قد تحتاج لإعادة مزامنة
    // لذلك نراقب تغيّرات DOM على select لالتقاط أي تغيير تم برمجياً
    if (sel) {
      const obs = new MutationObserver(() => { onCategoryChange(); });
      obs.observe(sel, { attributes: true, childList: true, subtree: true });
    }
  });

  // Expose function to console for testing
  window.complaintUrgency = complaintUrgency;
})();