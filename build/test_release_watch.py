import os
import unittest

import release_watch as rw

R = [{"version": "7.4.0-rc.2", "sha256": "b"}, {"version": "7.3.4", "sha256": "a"}, {"version": "7.4.0", "sha256": "c"}]


@unittest.skipUnless(os.environ.get("NERDTOOLS"), "NERDTOOLS must point at the pinned NerdTools checkout")
class SelectTest(unittest.TestCase):
    def test_skips_tested_and_open_issue(self):
        tested = {"7.3.4": {"sha256": "a", "package": "p"}, "7.4.0": {"sha256": "OLD", "package": "p"}}
        chosen = rw.select(R, tested, {rw.issue_title("7.4.0-rc.2")}, "p")
        self.assertEqual([r["version"] for r in chosen], ["7.4.0"])  # re-pushed ZIP is retested
        self.assertEqual(rw.select(R, tested, set(), "q")[0]["version"], "7.4.0-rc.2")  # new package retests all

    def test_dry_run_is_newest_final_release(self):
        self.assertEqual(rw.select(R, {}, set(), "p", dry_run=True)[0]["version"], "7.4.0")

    def test_override_ignores_dedup_and_rejects_unknown(self):
        tested = {"7.3.4": {"sha256": "a", "package": "p"}}
        self.assertEqual(rw.select(R, tested, set(), "p", version="7.3.4")[0]["sha256"], "a")
        with self.assertRaises(ValueError):
            rw.select(R, {}, set(), "p", version="9.9.9")


class OutcomeTest(unittest.TestCase):
    def test_results_and_record(self):
        jobs = [{"name": "verify 7.3.4 / verify", "conclusion": "success", "html_url": "u1", "steps": []},
                {"name": "verify 7.4.0 / verify", "conclusion": "failure", "html_url": "u2",
                 "steps": [{"name": "Install with upgradepkg into the exact Unraid 7.4.0 root", "conclusion": "failure"}]},
                {"name": "verify 7.5.0 / verify", "conclusion": "failure", "html_url": "u3",
                 "steps": [{"name": "Install archive tools", "conclusion": "failure"}]},
                {"name": "discover", "conclusion": "success", "html_url": "u0", "steps": []}]
        out = rw.results([R[1], R[2]], jobs)
        self.assertEqual([o["ok"] for o in out], [True, False])
        self.assertEqual(rw.record({}, out, "p", "2026-01-01"),
                         {"7.3.4": {"sha256": "a", "package": "p", "verified": "2026-01-01"}})
        with self.assertRaises(ValueError):
            rw.results([R[0]], jobs)
        with self.assertRaises(ValueError):  # runner failure is not a verification result
            rw.results([{"version": "7.5.0", "sha256": "d"}], jobs)


if __name__ == "__main__":
    unittest.main()
