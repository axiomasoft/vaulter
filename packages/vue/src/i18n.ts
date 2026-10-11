import { inject, type InjectionKey } from 'vue'

/** The strings the built-in viewers render; hosts override any of them per locale. */
export type VaulterMessageKey =
  | 'viewer.unsupported.title'
  | 'viewer.unsupported.typeUnavailable'
  | 'viewer.unsupported.noViewer'
  | 'viewer.download'
  | 'viewer.loading'
  | 'viewer.previewFailed'
  | 'viewer.downloadNotAllowed'
  | 'name.empty'
  | 'name.path_alias'
  | 'name.separator'
  | 'name.control_char'
  | 'name.reserved_char'
  | 'name.trailing_dot_or_space'
  | 'name.leading_or_trailing_space'
  | 'name.reserved_name'
  | 'name.too_long'

export type VaulterMessages = Partial<Record<VaulterMessageKey, string>>

const DEFAULT_MESSAGES: Record<string, Record<VaulterMessageKey, string>> = {
  en: {
    'viewer.unsupported.title': 'This item cannot be opened here',
    'viewer.unsupported.typeUnavailable': 'The document type "{type}" is not available in this application.',
    'viewer.unsupported.noViewer': 'No viewer is registered for this item.',
    'viewer.download': 'Download',
    'viewer.loading': 'Loading…',
    'viewer.previewFailed': 'The preview could not be loaded.',
    'viewer.downloadNotAllowed': 'You can see this item but not its contents.',
    'name.empty': 'The name must not be empty.',
    'name.path_alias': 'The name must not be "." or "..".',
    'name.separator': 'The name must not contain path separators (/ or \\).',
    'name.control_char': 'The name must not contain control characters.',
    'name.reserved_char': 'The name must not contain < > : " | ? * or invalid characters.',
    'name.trailing_dot_or_space': 'The name must not end with a dot.',
    'name.leading_or_trailing_space': 'The name must not start or end with a space.',
    'name.reserved_name': 'This name is reserved by operating systems (CON, NUL, COM1, desktop.ini, …).',
    'name.too_long': 'The name must be at most 255 characters.',
  },
  ru: {
    'viewer.unsupported.title': 'Этот элемент нельзя открыть здесь',
    'viewer.unsupported.typeUnavailable': 'Тип документа «{type}» недоступен в этом приложении.',
    'viewer.unsupported.noViewer': 'Для этого элемента не зарегистрирован просмотрщик.',
    'viewer.download': 'Скачать',
    'viewer.loading': 'Загрузка…',
    'viewer.previewFailed': 'Не удалось загрузить предпросмотр.',
    'viewer.downloadNotAllowed': 'Вы видите элемент, но не его содержимое.',
    'name.empty': 'Имя не должно быть пустым.',
    'name.path_alias': 'Имя не может быть «.» или «..».',
    'name.separator': 'Имя не должно содержать разделители пути (/ или \\).',
    'name.control_char': 'Имя не должно содержать управляющие символы.',
    'name.reserved_char': 'Имя не должно содержать < > : " | ? * и недопустимые символы.',
    'name.trailing_dot_or_space': 'Имя не должно оканчиваться точкой.',
    'name.leading_or_trailing_space': 'Имя не должно начинаться или заканчиваться пробелом.',
    'name.reserved_name': 'Это имя зарезервировано операционными системами (CON, NUL, COM1, desktop.ini, …).',
    'name.too_long': 'Имя должно быть не длиннее 255 символов.',
  },
}

export interface VaulterI18nOptions {
  /** `en` (default) and `ru` ship with the package; any other locale falls back to `en` per key. */
  locale?: string
  /** Host overrides, merged over the shipped messages of the same locale. */
  messages?: Record<string, VaulterMessages>
}

export interface VaulterI18n {
  locale: string
  t(key: VaulterMessageKey, params?: Record<string, string>): string
}

export const vaulterI18nKey: InjectionKey<VaulterI18n> = Symbol('vaulter.i18n')

export function createVaulterI18n(options: VaulterI18nOptions = {}): VaulterI18n {
  const locale = options.locale ?? 'en'

  return {
    locale,
    t(key, params = {}) {
      const template = options.messages?.[locale]?.[key]
        ?? DEFAULT_MESSAGES[locale]?.[key]
        ?? DEFAULT_MESSAGES.en[key]

      return template.replace(/\{(\w+)\}/g, (placeholder, name: string) => params[name] ?? placeholder)
    },
  }
}

const fallbackI18n = createVaulterI18n()

/** The i18n the plugin provided; the English defaults when the component runs without it. */
export function useVaulterI18n(): VaulterI18n {
  return inject(vaulterI18nKey, fallbackI18n)
}
