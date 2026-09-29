--
-- PostgreSQL database dump
--

\restrict mA91oWCou2a4wu7VijBuF0dQFHFq7Z9DKmwrRyaW1s6zKGzSz4FzPU8Js1XuTPU

-- Dumped from database version 16.15 (Debian 16.15-1.pgdg12+2)
-- Dumped by pg_dump version 16.15 (Debian 16.15-1.pgdg12+2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: v_outbox; Type: TABLE DATA; Schema: public; Owner: vaulter
--

COPY public.v_outbox (id, event_id, panel, topic, event, payload, available_at, dispatched_at, attempts, status, created_at, updated_at) FROM stdin;
40859	p6:53e8508a-d581-45af-ab2d-29b5947ea018:outbox-01m36jtwcjj6c2sh9bdyg61fj9	default	\N	load.queue	{"event_id":"p6:53e8508a-d581-45af-ab2d-29b5947ea018:outbox-01m36jtwcjj6c2sh9bdyg61fj9"}	2026-09-23 07:31:42	2026-09-23 07:32:58	0	dispatched	2026-09-23 07:31:42	2026-09-23 07:32:58
40860	p6:53e8508a-d581-45af-ab2d-29b5947ea018:outbox-01m36jtwcjj6c2sh9bdyg61fja	default	\N	load.queue	{"event_id":"p6:53e8508a-d581-45af-ab2d-29b5947ea018:outbox-01m36jtwcjj6c2sh9bdyg61fja"}	2026-09-23 07:31:42	2026-09-23 07:32:58	0	dispatched	2026-09-23 07:31:42	2026-09-23 07:32:58
40861	p6:53e8508a-d581-45af-ab2d-29b5947ea018:outbox-01m36jtwcjj6c2sh9bdyg61fjb	default	\N	load.queue	{"event_id":"p6:53e8508a-d581-45af-ab2d-29b5947ea018:outbox-01m36jtwcjj6c2sh9bdyg61fjb"}	2026-09-23 07:31:42	2026-09-23 07:32:58	0	dispatched	2026-09-23 07:31:42	2026-09-23 07:32:58
40862	p6:53e8508a-d581-45af-ab2d-29b5947ea018:outbox-01m36jtwcjj6c2sh9bdyg61fjc	default	\N	load.queue	{"event_id":"p6:53e8508a-d581-45af-ab2d-29b5947ea018:outbox-01m36jtwcjj6c2sh9bdyg61fjc"}	2026-09-23 07:31:42	2026-09-23 07:32:58	0	dispatched	2026-09-23 07:31:42	2026-09-23 07:32:58
\.


--
-- Data for Name: v_webhook_endpoints; Type: TABLE DATA; Schema: public; Owner: vaulter
--

COPY public.v_webhook_endpoints (id, url, events, signing_secret, is_active, last_triggered_at, failure_count, created_at, updated_at) FROM stdin;
2163	http://webhook-sink:8090/2xx?run_id=53e8508a-d581-45af-ab2d-29b5947ea018&cell_id=overlap&slot=1	["load.queue"]	eyJpdiI6ImdNVEVha3NmVUZHRUs2cGNHT2NsZmc9PSIsInZhbHVlIjoiSHJndVhWWDRnOGt3UmlkSUpVaTVjWFB6dDRtSmhXSUl3MllZN2YzbWtwL2tSL0FuRnlyUmJEVVc5a3I3V1RhQiIsIm1hYyI6Ijc4NjBlOTJmMjZkOWNhMjkyZTRmZWY1NGZhNzAwNDRjMDYyMGQ4NWVmODgwNTZhODZlNTJkNDFhYWQxZWM2ZjkiLCJ0YWciOiIifQ==	t	2026-09-23 08:28:27	0	2026-09-23 07:31:42	2026-09-23 08:28:27
\.


--
-- Data for Name: v_webhook_deliveries; Type: TABLE DATA; Schema: public; Owner: vaulter
--

COPY public.v_webhook_deliveries (id, endpoint_id, event, payload, status, attempts, next_retry_at, last_error, created_at, updated_at) FROM stdin;
71717	2163	load.queue	{"event_id":"p6:53e8508a-d581-45af-ab2d-29b5947ea018:outbox-01m36jtwcjj6c2sh9bdyg61fja"}	failed	2	2026-09-23 08:30:29	cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for http://webhook-sink:8090/2xx?run_id=53e8508a-d581-45af-ab2d-29b5947ea018&cell_id=overlap&slot=1	2026-09-23 07:32:58	2026-09-23 08:29:59
71716	2163	load.queue	{"event_id":"p6:53e8508a-d581-45af-ab2d-29b5947ea018:outbox-01m36jtwcjj6c2sh9bdyg61fj9"}	failed	2	2026-09-23 08:30:29	cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for http://webhook-sink:8090/2xx?run_id=53e8508a-d581-45af-ab2d-29b5947ea018&cell_id=overlap&slot=1	2026-09-23 07:32:58	2026-09-23 08:29:59
71718	2163	load.queue	{"event_id":"p6:53e8508a-d581-45af-ab2d-29b5947ea018:outbox-01m36jtwcjj6c2sh9bdyg61fjb"}	failed	1	2026-09-23 08:30:04	cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for http://webhook-sink:8090/2xx?run_id=53e8508a-d581-45af-ab2d-29b5947ea018&cell_id=overlap&slot=1	2026-09-23 07:32:58	2026-09-23 08:29:54
71719	2163	load.queue	{"event_id":"p6:53e8508a-d581-45af-ab2d-29b5947ea018:outbox-01m36jtwcjj6c2sh9bdyg61fjc"}	failed	1	2026-09-23 08:30:04	cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for http://webhook-sink:8090/2xx?run_id=53e8508a-d581-45af-ab2d-29b5947ea018&cell_id=overlap&slot=1	2026-09-23 07:32:58	2026-09-23 08:29:54
71715	2163	load.queue	{"event_id":"p6:53e8508a-d581-45af-ab2d-29b5947ea018:due-01m36jtwjx58269bz1ggv5qb8y"}	failed	2	2026-09-23 08:30:34	cURL error 28: Operation timed out after 5000 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for http://webhook-sink:8090/2xx?run_id=53e8508a-d581-45af-ab2d-29b5947ea018&cell_id=overlap&slot=1	2026-09-23 07:30:42	2026-09-23 08:30:04
71714	2163	load.queue	{"event_id":"p6:53e8508a-d581-45af-ab2d-29b5947ea018:due-01m36jtwjx58269bz1ggv5qb8x"}	failed	2	2026-09-23 08:30:34	cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for http://webhook-sink:8090/2xx?run_id=53e8508a-d581-45af-ab2d-29b5947ea018&cell_id=overlap&slot=1	2026-09-23 07:30:42	2026-09-23 08:30:04
\.


--
-- Name: v_outbox_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vaulter
--

SELECT pg_catalog.setval('public.v_outbox_id_seq', 80542, true);


--
-- Name: v_webhook_deliveries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vaulter
--

SELECT pg_catalog.setval('public.v_webhook_deliveries_id_seq', 172047, true);


--
-- Name: v_webhook_endpoints_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vaulter
--

SELECT pg_catalog.setval('public.v_webhook_endpoints_id_seq', 4325, true);


--
-- PostgreSQL database dump complete
--

\unrestrict mA91oWCou2a4wu7VijBuF0dQFHFq7Z9DKmwrRyaW1s6zKGzSz4FzPU8Js1XuTPU

