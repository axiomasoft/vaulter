// VLT-LOAD P2.4 — thin node-tree HTTP helpers shared by tree.js/mixed.js/smoke.js.
// Every call returns the raw k6 http.Response so callers stay in charge of check()/tags.
import http from 'k6/http';
import { API } from './config.js';

const JSON_HEADERS = { 'Content-Type': 'application/json' };

export function children(storageId, parentId, headers, tags) {
  const params = parentId ? { parent_id: String(parentId), per_page: 200 } : { per_page: 200 };
  const qs = Object.entries(params).map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');

  return http.get(`${API}/storages/${storageId}/nodes?${qs}`, { headers, tags });
}

export function show(nodeId, headers, tags) {
  return http.get(`${API}/nodes/${nodeId}`, { headers, tags });
}

export function search(storageId, term, headers, tags) {
  return http.get(`${API}/storages/${storageId}/search?q=${encodeURIComponent(term)}`, { headers, tags });
}

export function trashed(storageId, headers, tags) {
  return http.get(`${API}/storages/${storageId}/trash`, { headers, tags });
}

export function shared(headers, tags) {
  return http.get(`${API}/shared`, { headers, tags });
}

export function createFolder(storageId, name, parentId, headers, tags) {
  const body = parentId ? { name, parent_id: parentId } : { name };

  return http.post(`${API}/storages/${storageId}/folders`, JSON.stringify(body), {
    headers: { ...headers, ...JSON_HEADERS },
    tags,
  });
}

export function rename(nodeId, name, headers, tags) {
  return http.patch(`${API}/nodes/${nodeId}`, JSON.stringify({ name }), {
    headers: { ...headers, ...JSON_HEADERS },
    tags,
  });
}

export function move(nodeId, parentId, headers, tags) {
  const body = parentId ? { parent_id: parentId } : {};

  return http.post(`${API}/nodes/${nodeId}/move`, JSON.stringify(body), {
    headers: { ...headers, ...JSON_HEADERS },
    tags,
  });
}

export function copy(nodeId, name, parentId, headers, tags) {
  const body = { name, ...(parentId ? { parent_id: parentId } : {}) };

  return http.post(`${API}/nodes/${nodeId}/copy`, JSON.stringify(body), {
    headers: { ...headers, ...JSON_HEADERS },
    tags,
  });
}

export function trash(nodeId, headers, tags) {
  return http.del(`${API}/nodes/${nodeId}`, null, { headers, tags });
}

export function restore(nodeId, headers, tags) {
  return http.post(`${API}/nodes/${nodeId}/restore`, null, { headers, tags });
}

export function forceDelete(nodeId, headers, tags) {
  return http.del(`${API}/nodes/${nodeId}/force`, null, { headers, tags });
}
