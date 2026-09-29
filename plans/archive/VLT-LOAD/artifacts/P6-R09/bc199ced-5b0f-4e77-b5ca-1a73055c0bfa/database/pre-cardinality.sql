--
-- PostgreSQL database dump
--

\restrict y6csYHWn4BRVPDPAC2y7dw3fpVP0ww3OzsP9qgM7LsZJdLtq3Kw58w7tXslLqzo

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
\.


--
-- Data for Name: v_webhook_endpoints; Type: TABLE DATA; Schema: public; Owner: vaulter
--

COPY public.v_webhook_endpoints (id, url, events, signing_secret, is_active, last_triggered_at, failure_count, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: v_webhook_deliveries; Type: TABLE DATA; Schema: public; Owner: vaulter
--

COPY public.v_webhook_deliveries (id, endpoint_id, event, payload, status, attempts, next_retry_at, last_error, created_at, updated_at) FROM stdin;
\.


--
-- Name: v_outbox_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vaulter
--

SELECT pg_catalog.setval('public.v_outbox_id_seq', 57505, true);


--
-- Name: v_webhook_deliveries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vaulter
--

SELECT pg_catalog.setval('public.v_webhook_deliveries_id_seq', 131641, true);


--
-- Name: v_webhook_endpoints_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vaulter
--

SELECT pg_catalog.setval('public.v_webhook_endpoints_id_seq', 2188, true);


--
-- PostgreSQL database dump complete
--

\unrestrict y6csYHWn4BRVPDPAC2y7dw3fpVP0ww3OzsP9qgM7LsZJdLtq3Kw58w7tXslLqzo

