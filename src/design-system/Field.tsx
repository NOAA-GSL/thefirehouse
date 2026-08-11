import { useId } from 'react';
import './Field.css';

interface SharedFieldProps {
  label: string;
  placeholder?: string;
  helperText?: string;
  error?: string;
  disabled?: boolean;
  required?: boolean;
}

export interface TextFieldProps extends SharedFieldProps {
  value?: string;
  onChange?: (value: string) => void;
}

export interface TextAreaProps extends TextFieldProps {
  maxLength?: number;
  rows?: number;
}

/**
 * Both fields wire label/helper/error together with real `htmlFor` + `aria-describedby`
 * + `aria-invalid`. The source components rendered a bare `<label>` next to the input
 * with no association, which reads as an orphaned string to a screen reader.
 */
function useFieldIds(helperText?: string, error?: string) {
  const id = useId();
  const describedBy = error || helperText ? `${id}-desc` : undefined;
  return { id, describedBy };
}

/** Single-line labeled text input with default/focus/error/disabled states. */
export function TextField({
  label,
  placeholder,
  value = '',
  onChange,
  helperText,
  error,
  disabled = false,
  required = false,
}: TextFieldProps) {
  const { id, describedBy } = useFieldIds(helperText, error);
  return (
    <div className="fh-field">
      <label className="fh-field__label" htmlFor={id}>
        {label}
        {required && <span className="fh-field__required"> *</span>}
      </label>
      <input
        id={id}
        className={`fh-field__control${error ? ' fh-field__control--error' : ''}`}
        value={value}
        placeholder={placeholder}
        disabled={disabled}
        required={required}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        onChange={(e) => onChange?.(e.target.value)}
      />
      {(helperText || error) && (
        <span id={describedBy} className={`fh-field__help${error ? ' fh-field__help--error' : ''}`}>
          {error || helperText}
        </span>
      )}
    </div>
  );
}

/** Multi-line answer field for open-ended submissions, with optional character count. */
export function TextArea({
  label,
  placeholder,
  value = '',
  onChange,
  helperText,
  error,
  maxLength,
  rows = 5,
  disabled = false,
  required = false,
}: TextAreaProps) {
  const { id, describedBy } = useFieldIds(helperText, error);
  return (
    <div className="fh-field">
      <label className="fh-field__label" htmlFor={id}>
        {label}
        {required && <span className="fh-field__required"> *</span>}
      </label>
      <textarea
        id={id}
        className={`fh-field__control fh-field__control--area${error ? ' fh-field__control--error' : ''}`}
        value={value}
        placeholder={placeholder}
        disabled={disabled}
        required={required}
        rows={rows}
        maxLength={maxLength}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        onChange={(e) => onChange?.(e.target.value)}
      />
      <div className="fh-field__footer">
        <span id={describedBy} className={`fh-field__help${error ? ' fh-field__help--error' : ''}`}>
          {error || helperText}
        </span>
        {maxLength && (
          <span className="fh-field__count">
            {value.length}/{maxLength}
          </span>
        )}
      </div>
    </div>
  );
}
