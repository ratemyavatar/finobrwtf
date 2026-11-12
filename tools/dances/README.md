# dances

builds the in game dances from a roblox animation pack.

download the pack, turn it into xml with `RbxmReader::toXml`, then run:

```bash
python3 tools/dances/build_dances.py pack.xml storage/rbx/files/dances
```

it keeps r6 dances only, drops junk names and copies, sets them to action priority, and writes `list.txt` with the ids.
