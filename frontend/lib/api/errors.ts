export class ApiError extends Error {
  status: number;
  payload?: unknown;

  constructor(message: string, status: number, payload?: unknown) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.payload = payload;
  }

  /** Whether the error is likely transient and the user could retry. */
  get isRetryable(): boolean {
    return [408, 429, 500, 502, 503, 504].includes(this.status);
  }

  /** Human-friendly description based on HTTP status for toast display. */
  get userHint(): string {
    switch (this.status) {
      case 400:
        return "Los datos enviados no son válidos. Verifica los campos.";
      case 404:
        return "El recurso solicitado no existe.";
      case 408:
        return "La petición excedió el tiempo de espera. Intenta de nuevo.";
      case 429:
        return "Límite de peticiones alcanzado. Espera unos segundos.";
      case 502:
      case 504:
        return "El backend no respondió a tiempo. Intenta reducir el volumen.";
      case 503:
        return "El servicio está temporalmente ocupado. Reintenta en un momento.";
      default:
        return this.message;
    }
  }
}

/**
 * Extracts a user-facing description from any thrown error,
 * preferring ApiError.userHint when available.
 */
export function describeError(error: unknown): string {
  if (error instanceof ApiError) return error.userHint;
  if (error instanceof Error) return error.message;
  return "Error desconocido.";
}

/**
 * Returns true if the error is an ApiError that the user can retry.
 */
export function isRetryableError(error: unknown): boolean {
  return error instanceof ApiError && error.isRetryable;
}
