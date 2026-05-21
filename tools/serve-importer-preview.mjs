import { createReadStream, existsSync, statSync } from "node:fs";
import { createServer } from "node:http";
import { extname, join, normalize, resolve } from "node:path";

const root = resolve(process.cwd());
const port = Number(process.env.PORT || 8080);
const contentTypes = {
	".html": "text/html; charset=utf-8",
	".js": "text/javascript; charset=utf-8",
	".css": "text/css; charset=utf-8",
	".md": "text/markdown; charset=utf-8",
	".png": "image/png",
	".jpg": "image/jpeg",
	".jpeg": "image/jpeg",
	".svg": "image/svg+xml",
};

function fileForUrl(url) {
	const parsed = new URL(url, "http://localhost");
	let pathname = decodeURIComponent(parsed.pathname);
	if (pathname === "/") {
		pathname = "/docs/importer/progress-flow-explorations/v2/index.html";
	}
	const candidate = normalize(join(root, pathname));
	if (!candidate.startsWith(root)) {
		return null;
	}
	if (existsSync(candidate) && statSync(candidate).isDirectory()) {
		return join(candidate, "index.html");
	}
	return candidate;
}

const server = createServer((request, response) => {
	const file = fileForUrl(request.url || "/");
	if (!file || !existsSync(file) || !statSync(file).isFile()) {
		response.writeHead(404, { "content-type": "text/plain; charset=utf-8" });
		response.end("Not found\n");
		return;
	}
	response.writeHead(200, {
		"content-type": contentTypes[extname(file)] || "application/octet-stream",
		"cache-control": "no-store",
	});
	createReadStream(file).pipe(response);
});

server.listen(port, "0.0.0.0", () => {
	console.log(`Importer preview server listening on http://0.0.0.0:${port}`);
});
