-- PPPoE Plans Cleanup Script
-- Run this inside the phpnuxbill database to remove duplicates and unwanted plans

-- First, let's see what we have
SELECT id, name_plan, price, validity, routers, type FROM tbl_plans WHERE type = 'PPPOE' ORDER BY name_plan;

-- Remove duplicates with router "1" (keep only "Main PPPoE Router" ones)
-- 75Mbps duplicate with router "1"
DELETE FROM tbl_plans WHERE name_plan = '75Mbps' AND type = 'PPPOE' AND routers = '1';

-- 90Mbps duplicate with router "1"
DELETE FROM tbl_plans WHERE name_plan = '90Mbps' AND type = 'PPPOE' AND routers = '1';

-- Remove 100Mbps, 150Mbps, 200Mbps plans
DELETE FROM tbl_plans WHERE name_plan = '100Mbps' AND type = 'PPPOE';
DELETE FROM tbl_plans WHERE name_plan = '150Mbps' AND type = 'PPPOE';
DELETE FROM tbl_plans WHERE name_plan = '200Mbps' AND type = 'PPPOE';

-- Verify cleanup
SELECT id, name_plan, price, validity, routers, type FROM tbl_plans WHERE type = 'PPPOE' ORDER BY name_plan;

-- Expected remaining PPPoE plans:
-- 50Mbps - 500 BDT - 30 Days - Main PPPoE Router
-- 75Mbps - 0 BDT - 30 Days - Main PPPoE Router
-- 90Mbps - 0 BDT - 30 Days - Main PPPoE Router
-- Suspended - 0 BDT - 30 Days - Main PPPoE Router
-- Unlimited - 500 BDT - 30 Mins - Main PPPoE Router
