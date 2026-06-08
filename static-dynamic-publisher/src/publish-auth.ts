export interface PublishAuthConfig {
  /**
   * Owner-controlled publish secret from Worker secrets or local CLI config.
   * Browser/admin code should never receive this value.
   */
  secret?: string;
  realm?: string;
}

export type PublishAuthFailureCode =
  | "publish_secret_not_configured"
  | "missing_authorization"
  | "invalid_authorization_scheme"
  | "invalid_publish_secret";

export type PublishAuthResult =
  | {
      authorized: true;
    }
  | {
      authorized: false;
      code: PublishAuthFailureCode;
      message: string;
      status: 401 | 403 | 503;
      headers: HeadersInit;
    };

const DEFAULT_REALM = "static-dynamic-publisher";

function constantTimeEqual(left: string, right: string): boolean {
  const encoder = new TextEncoder();
  const leftBytes = encoder.encode(left);
  const rightBytes = encoder.encode(right);
  const maxLength = Math.max(leftBytes.length, rightBytes.length, 1);
  let diff = leftBytes.length ^ rightBytes.length;

  for (let index = 0; index < maxLength; index += 1) {
    diff |= (leftBytes[index % leftBytes.length] ?? 0) ^ (rightBytes[index % rightBytes.length] ?? 0);
  }

  return diff === 0;
}

function unauthorizedHeaders(realm: string): HeadersInit {
  return {
    "WWW-Authenticate": `Bearer realm="${realm}"`,
  };
}

export function authenticatePublishRequest(
  request: Request,
  config: PublishAuthConfig,
): PublishAuthResult {
  const realm = config.realm ?? DEFAULT_REALM;

  if (!config.secret) {
    return {
      authorized: false,
      code: "publish_secret_not_configured",
      message: "Publish authentication is not configured for this site.",
      status: 503,
      headers: {},
    };
  }

  const authorization = request.headers.get("Authorization");

  if (!authorization) {
    return {
      authorized: false,
      code: "missing_authorization",
      message: "Publish requests must include owner authorization.",
      status: 401,
      headers: unauthorizedHeaders(realm),
    };
  }

  const bearerMatch = /^Bearer\s+(.+)$/i.exec(authorization.trim());

  if (!bearerMatch) {
    return {
      authorized: false,
      code: "invalid_authorization_scheme",
      message: "Publish requests must use bearer token authorization.",
      status: 401,
      headers: unauthorizedHeaders(realm),
    };
  }

  if (!constantTimeEqual(bearerMatch[1], config.secret)) {
    return {
      authorized: false,
      code: "invalid_publish_secret",
      message: "Publish authorization was rejected.",
      status: 403,
      headers: {},
    };
  }

  return {
    authorized: true,
  };
}

export function publishAuthFailureResponse(result: Exclude<PublishAuthResult, { authorized: true }>): Response {
  return new Response(
    JSON.stringify(
      {
        status: result.code,
        message: result.message,
      },
      null,
      2,
    ),
    {
      status: result.status,
      headers: {
        "Content-Type": "application/json;charset=UTF-8",
        "Cache-Control": "no-store",
        ...result.headers,
      },
    },
  );
}
