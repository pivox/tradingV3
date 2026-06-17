import pytest

from app.services.sets import list_active_sets, list_sets


def test_list_active_excludes_disabled():
    active_ids = {s.set_id for s in list_active_sets()}
    assert "fake_regular_demo_disabled" not in active_ids
    assert all(s.enabled for s in list_active_sets())


def test_default_active_count():
    # Deux sets actifs simulés, un désactivé.
    assert len(list_active_sets()) == 2


def test_list_active_sorted_by_priority_desc():
    priorities = [s.priority for s in list_active_sets()]
    assert priorities == sorted(priorities, reverse=True)


def test_sets_are_immutable():
    a_set = list_sets()[0]
    with pytest.raises(Exception):
        a_set.enabled = False
