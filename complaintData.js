// ================================
// complaintData.js
// تجميعة قوائم و دوال مساعدة للاستخدام في واجهة الشكاوى.
// - قوائم (نصية) قابلة للاستيراد
// - دوال مساعدة: احتساب أولوية الاستجابة، تحويل حالة الشكوى، تعبئة <select>، التحقق، بحث سريع
// - مُصمّم كـ ES module: استخدم `import { ... } from './complaintData.js'`
// - ترميز UTF-8.
//
// تم تحسين القائمة الأصلية بإضافة توثيق، بعض القيم الشائعة، ودوال مساعدة عملية.
// ================================

/**
 * 1) مصادر الشكاوى
 */
const complaintSources = [
  "الخط الساخن",
  "الايميل",
  "شخصياً بالمكتب",
  "الادارة",
  "رسالة نصية",
  "تطبيق الهاتف",
];

/**
 * 2) حالة العينة المتعلقة بالشكوى
 */
const sampleStatusOptions = [
  "موجودة",
  "غير موجودة",
  "تم ارسال صورها بالهاتف",
  "تم اتلافها بواسطة الشاكى",
  "تم ارجاعها للموقع",
  "تم احضار العينة للقسم",
  "لا يوجد عينة للشكوى",
];

/**
 * 3) طرق/إجراءات التواصل مع الشاكي
 */
const complainantContactActions = [
  "تم مقابلة الشاكى واستلام العينة",
  "تم مقابلة الشاكى ولم يحضر العينة",
  "تم الاتصال بالشاكى ولم يحضر العينة",
  "تم ارسال صور العينة عبر تطبيق الرسائل",
  "تم الاتصال بالشاكى واخذ افادته",
  "تم الاتصال بالشاكى ولم يجيب على الهاتف",
  "تم مقابلة الشاكى واخذ افادته شفهياً",
];

/**
 * 4) تصنيف الشكوى (الفئات)
 */
const complaintCategories = [
  "النظافة العامة",
  "المبانى والمنشات",
  "طرق اعداد وحفظ",
  "الغش التجارى",
  "الحشرات والقوارض",
  "المخالفات العامة",
  "مخالفات سوق الخضار والسمك",
  "مخالفات قسم البيطرة",
  "اشتباة تسمم غذائى",
  "شكوى تحتاج الى الاشتراك مع جهات اخرى",
  "اشتباة تسمم غذائى بدون تقرير مستشفى",
  "إجراءات احترازية",
];

/**
 * 5) نوع اشتباه التسمم الغذائي
 */
const foodPoisoningSuspects = [
  "داخل الامارة",
  "خارج الامارة",
  "منزلى",
  "لا يوجد",
];

/**
 * 5.1) إجراءات القسم - صلاحية المدير والسوبر أدمن
 */
const sectionActionsOptions = [
  "حفظ",
  "مخالفة",
  "تحفظ",
  "اخذ عينة",
  "انذار",
  "متابعة من المفتش",
  "التحويل الى جهة الاختصاص",
];

/**
 * 5.2) دالة تحديد حالة الشكوى تلقائياً بناءً على إجراءات القسم
 *      - حفظ → الشكوى غير صحيحة
 *      - مخالفة → الشكوى صحيحة
 *      - تحفظ, اخذ عينة, انذار, متابعة من المفتش → الشكوى قيد الاجراء
 *      - التحويل الى جهة الاختصاص → تم تحويل الشكوى الى جهة الاختصاص
 */
function derivedComplaintStatus(sectionAction) {
  if (!sectionAction || typeof sectionAction !== 'string') return '';
  const action = sectionAction.trim();
  switch (action) {
    case "حفظ":
      return "الشكوى غير صحيحة";
    case "مخالفة":
      return "الشكوى صحيحة";
    case "تحفظ":
    case "اخذ عينة":
    case "انذار":
    case "متابعة من المفتش":
      return "الشكوى قيد الاجراء";
    case "التحويل الى جهة الاختصاص":
      return "تم تحويل الشكوى الى جهة الاختصاص";
    default:
      return '';
  }
}

/**
 * 6) دالة حساب سرعة الاستجابة (أولوية) بناءً على الفئة
 *    ترجع نصاً مختصراً يوضّح الإجراء المتوقع.
 */
function complaintUrgency(category) {
  if (!category || typeof category !== 'string') return 'غير محددة';
  switch (category) {
    case "النظافة العامة":
    case "طرق اعداد وحفظ":
    case "الغش التجارى":
    case "الحشرات والقوارض":
    case "المخالفات العامة":
    case "مخالفات سوق الخضار والسمك":
    case "مخالفات قسم البيطرة":
    case "اشتباة تسمم غذائى بدون تقرير مستشفى":
      return "عاجلة — خلال يوم عمل واحد";
    case "المبانى والمنشات":
      return "عادية — خلال 5 أيام عمل";
    case "اشتباة تسمم غذائى":
    case "إجراءات احترازية":
      return "طارئة — استجابة فورية";
    case "شكوى تحتاج الى الاشتراك مع جهات اخرى":
      return "معقدة — يحتاج تنسيق بين جهات";
    default:
      return "غير محددة";
  }
}

/**
 * 7) دالة ترجمة/تصنيف حالة الشكوى النصية إلى وضع عرضي أو وصفي
 *    تقبل رمز حالة نصي أو قيمة وتعيد وصف الحالة.
 */
function complaintStatusLabel(statusCode) {
  if (!statusCode) return "غير محددة";
  const s = String(statusCode).trim();
  switch (s) {
    case "حفظ":
      return "الشكوى غير صحيحة (محفوظة)";
    case "مخالفة":
      return "الشكوى صحيحة (تمت المخالفة)";
    case "تحفظ":
    case "اخذ عينة":
    case "انذار":
    case "متابعة من المفتش":
      return "قيد الإجراء";
    case "التحويل الى جهة الاختصاص":
      return "تم التحويل إلى جهة الاختصاص";
    case "تم الإغلاق":
      return "مغلقة";
    default:
      return s; // إرجاع النص كما هو إذا لم يكن معروفاً
  }
}

/**
 * 8) دوال مساعدة DOM لملء عناصر select (يسهل إعادة الاستخدام)
 *
 * populateSelect(selectElement, items, options)
 * - selectElement: عنصر <select> DOM أو selector string
 * - items: مصفوفة من السلاسل أو كائنات { value, text }
 * - options: { includeEmpty: boolean, emptyText: string, preserveExisting: boolean }
 *
 * Returns: the <select> DOM element after population.
 */
function populateSelect(selectElement, items = [], options = {}) {
  if (typeof selectElement === 'string') selectElement = document.querySelector(selectElement);
  if (!selectElement) throw new Error('populateSelect: invalid select element');

  const { includeEmpty = true, emptyText = '— اختر —', preserveExisting = false } = options;

  if (!preserveExisting) selectElement.innerHTML = '';

  if (includeEmpty) {
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = emptyText;
    selectElement.appendChild(opt);
  }

  items.forEach(it => {
    const opt = document.createElement('option');
    if (typeof it === 'string') {
      opt.value = it;
      opt.textContent = it;
    } else if (typeof it === 'object' && it !== null) {
      // object: { value, text, attrs }
      opt.value = it.value != null ? String(it.value) : (it.text != null ? String(it.text) : '');
      opt.textContent = it.text != null ? String(it.text) : String(it.value);
      if (it.attrs && typeof it.attrs === 'object') {
        Object.keys(it.attrs).forEach(k => opt.setAttribute(k, String(it.attrs[k])));
      }
    }
    selectElement.appendChild(opt);
  });

  return selectElement;
}

/**
 * 9) دالة مساعدة لإنشاء عنصر option سهل الاستخدام
 */
function createOption(value, text, attrs = {}) {
  const o = { value, text, attrs };
  return o;
}

/**
 * 10) Validation helpers (بسيطة)
 */
function isValidCategory(cat) {
  if (!cat || typeof cat !== 'string') return false;
  return complaintCategories.includes(cat);
}
function isValidSampleStatus(s) {
  return sampleStatusOptions.includes(String(s));
}

/**
 * 11) Map / Normalization helpers
 *    - normalizeCategory: يحاول أن يجد أقرب قيمة من القائمة (حرفياً أو بحالة ignore-case)
 */
function normalizeCategory(input) {
  if (!input) return '';
  const s = String(input).trim();
  if (complaintCategories.includes(s)) return s;
  // case-insensitive match
  const found = complaintCategories.find(c => c.toLowerCase() === s.toLowerCase());
  if (found) return found;
  // fuzzy simple: contains match
  const contains = complaintCategories.find(c => s.toLowerCase().indexOf(c.toLowerCase()) !== -1 || c.toLowerCase().indexOf(s.toLowerCase()) !== -1);
  return contains || s;
}

/**
 * 12) Export everything needed
 */
export {
  // lists
  complaintSources,
  sampleStatusOptions,
  complainantContactActions,
  complaintCategories,
  foodPoisoningSuspects,
  sectionActionsOptions,
  // functions
  complaintUrgency,
  complaintStatusLabel,
  derivedComplaintStatus,
  populateSelect,
  createOption,
  isValidCategory,
  isValidSampleStatus,
  normalizeCategory,
};